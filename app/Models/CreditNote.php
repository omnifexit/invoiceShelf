<?php

namespace App\Models;

use App\Http\Requests\CreditNoteRequest;
use App\Services\SerialNumberFormatter;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Vinkla\Hashids\Facades\Hashids;

class CreditNote extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $appends = [
        'formattedCreditNoteDate',
        'formattedCreatedAt',
        'creditNotePdfUrl',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'tax' => 'integer',
            'base_amount' => 'integer',
            'base_tax' => 'integer',
            'exchange_rate' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function emailLogs(): MorphMany
    {
        return $this->morphMany('App\Models\EmailLog', 'mailable');
    }

    public function getFormattedCreditNoteDateAttribute($value)
    {
        $dateFormat = CompanySetting::getSetting('carbon_date_format', $this->company_id);

        return Carbon::parse($this->credit_note_date)->translatedFormat($dateFormat);
    }

    public function getFormattedCreatedAtAttribute($value)
    {
        $dateFormat = CompanySetting::getSetting('carbon_date_format', $this->company_id);

        return Carbon::parse($this->created_at)->translatedFormat($dateFormat);
    }

    public function getCreditNotePdfUrlAttribute()
    {
        return url('/credit-notes/pdf/'.$this->unique_hash);
    }

    public function scopeWhereCompany($query)
    {
        $query->where('credit_notes.company_id', request()->header('company'));
    }

    public function scopeWhereCompanyId($query, $company)
    {
        $query->where('credit_notes.company_id', $company);
    }

    public function scopeWhereSearch($query, $search)
    {
        foreach (explode(' ', $search) as $term) {
            $query->whereHas('customer', function ($query) use ($term) {
                $query->where('name', 'LIKE', '%'.$term.'%')
                    ->orWhere('contact_name', 'LIKE', '%'.$term.'%')
                    ->orWhere('company_name', 'LIKE', '%'.$term.'%');
            });
        }
    }

    public function scopeWhereCreditNoteNumber($query, $creditNoteNumber)
    {
        $query->where('credit_notes.credit_note_number', 'LIKE', '%'.$creditNoteNumber.'%');
    }

    public function scopeWhereInvoice($query, $invoiceId)
    {
        $query->where('credit_notes.invoice_id', $invoiceId);
    }

    public function scopeWhereCustomer($query, $customerId)
    {
        $query->where('credit_notes.customer_id', $customerId);
    }

    public function scopeCreditNotesBetween($query, Carbon $start, Carbon $end)
    {
        $query->whereBetween(
            'credit_notes.credit_note_date',
            [$start->format('Y-m-d'), $end->format('Y-m-d')]
        );
    }

    public function scopeWhereOrder($query, $orderByField, $orderBy)
    {
        $query->orderBy($orderByField, $orderBy);
    }

    public function scopePaginateData($query, $limit)
    {
        if ($limit == 'all') {
            return $query->get();
        }

        return $query->paginate($limit);
    }

    public function scopeApplyFilters($query, array $filters)
    {
        $filters = collect($filters);

        if ($filters->get('search')) {
            $query->whereSearch($filters->get('search'));
        }

        if ($filters->get('credit_note_number')) {
            $query->whereCreditNoteNumber($filters->get('credit_note_number'));
        }

        if ($filters->get('invoice_id')) {
            $query->whereInvoice($filters->get('invoice_id'));
        }

        if ($filters->get('customer_id')) {
            $query->whereCustomer($filters->get('customer_id'));
        }

        if ($filters->get('from_date') && $filters->get('to_date')) {
            $start = Carbon::createFromFormat('Y-m-d', $filters->get('from_date'));
            $end = Carbon::createFromFormat('Y-m-d', $filters->get('to_date'));
            $query->creditNotesBetween($start, $end);
        }

        $field = $filters->get('orderByField') ?: 'sequence_number';
        $orderBy = $filters->get('orderBy') === 'asc' ? 'asc' : 'desc';

        if (! in_array($field, ['name', 'invoice_number']) && ! str_contains($field, '.')) {
            $field = 'credit_notes.'.$field;
        }

        $query->whereOrder($field, $orderBy);
    }

    /**
     * Credit (part of) an invoice. The amount is checked again under a lock on
     * the invoice, so concurrent credit notes can never exceed the invoice total.
     */
    public static function createCreditNote(CreditNoteRequest $request): self
    {
        $data = $request->getCreditNotePayload();

        $creditNote = DB::transaction(function () use ($data) {
            /** @var Invoice $invoice */
            $invoice = Invoice::whereKey($data['invoice_id'])->lockForUpdate()->firstOrFail();

            if ($data['amount'] > $invoice->getCreditableAmount()) {
                throw ValidationException::withMessages([
                    'amount' => ['The credited amount may not exceed the remaining creditable amount of the invoice.'],
                ]);
            }

            $exchangeRate = (float) $invoice->exchange_rate ?: 1.0;
            $tax = self::calculateTax($invoice, (int) $data['amount']);

            $creditNote = self::create(array_merge($data, [
                'tax' => $tax,
                'base_tax' => (int) round($tax * $exchangeRate),
            ]));

            $serial = (new SerialNumberFormatter)
                ->setModel($creditNote)
                ->setCompany($creditNote->company_id)
                ->setCustomer($creditNote->customer_id)
                ->setNextNumbers();

            $creditNote->sequence_number = $serial->nextSequenceNumber;
            $creditNote->customer_sequence_number = $serial->nextCustomerSequenceNumber;
            $creditNote->unique_hash = Hashids::connection(CreditNote::class)->encode($creditNote->id);
            $creditNote->save();

            // A credited invoice has been issued; without this flag the status
            // would fall back to draft when it is recalculated.
            if (! $invoice->sent && ! $invoice->viewed) {
                $invoice->sent = true;
            }

            $invoice->subtractInvoicePayment($creditNote->amount);

            if ((string) $creditNote->currency_id !== (string) CompanySetting::getSetting('currency', $creditNote->company_id)) {
                ExchangeRateLog::addExchangeRateLog($creditNote);
            }

            return $creditNote;
        });

        return self::with(['customer', 'invoice', 'currency'])->find($creditNote->id);
    }

    /**
     * The tax part of a credit in cents, proportional to the invoice. The credit
     * note that completes the invoice gets the remainder, so the credited tax of
     * all credit notes adds up exactly to the tax of the invoice.
     */
    public static function calculateTax(Invoice $invoice, int $amount): int
    {
        $total = (int) $invoice->total;
        $invoiceTax = (int) $invoice->tax;

        if ($total <= 0 || $invoiceTax <= 0) {
            return 0;
        }

        $creditedAmount = (int) $invoice->creditNotes()->sum('amount');
        $creditedTax = (int) $invoice->creditNotes()->sum('tax');

        if ($creditedAmount + $amount >= $total) {
            return max(0, $invoiceTax - $creditedTax);
        }

        return intdiv($amount * $invoiceTax, $total);
    }

    public static function deleteCreditNotes(array $ids): bool
    {
        DB::transaction(function () use ($ids) {
            foreach ($ids as $id) {
                $creditNote = self::find($id);

                if (! $creditNote) {
                    continue;
                }

                Invoice::whereKey($creditNote->invoice_id)
                    ->lockForUpdate()
                    ->first()
                    ?->addInvoicePayment($creditNote->amount);

                $creditNote->delete();
            }
        });

        return true;
    }

    /**
     * Share the data of the credit note template. The addresses use the invoice
     * address formats and are rendered by the invoice, which has the same
     * customer and company.
     */
    public function sharePdfData(): void
    {
        $company = Company::find($this->company_id);

        App::setLocale(CompanySetting::getSetting('language', $this->company_id));

        $invoice = $this->invoice;

        view()->share([
            'creditNote' => $this,
            'invoice' => $invoice,
            'company_address' => $invoice ? $invoice->getCompanyAddress() : false,
            'billing_address' => $invoice ? $invoice->getCustomerBillingAddress() : false,
            'notes' => $this->getNotes(),
            'logo' => $company?->logo_path,
        ]);
    }

    public function getPDFData()
    {
        $this->sharePdfData();

        return PDF::loadView('app.pdf.credit-note.credit-note');
    }

    public function getPreview(): View
    {
        $this->sharePdfData();

        return view('app.pdf.credit-note.credit-note');
    }

    public function getPdfResponse(): Response
    {
        return response()->make($this->getPDFData()->stream(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->credit_note_number.'.pdf"',
        ]);
    }

    public function getNotes(): string
    {
        return nl2br(e((string) $this->notes));
    }
}

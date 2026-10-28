<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property Carbon $booking_date
 * @property Carbon|null $value_date
 * @property Carbon|null $processed_at
 * @property list<int>|null $candidate_invoice_ids
 */
class BankTransaction extends Model
{
    use HasFactory;

    public const STATUS_UNMATCHED = 'UNMATCHED';

    public const STATUS_AMBIGUOUS = 'AMBIGUOUS';

    public const STATUS_MATCHED = 'MATCHED';

    public const STATUS_LINKED = 'LINKED';

    public const STATUS_IGNORED = 'IGNORED';

    public const OPEN_STATUSES = [self::STATUS_UNMATCHED, self::STATUS_AMBIGUOUS];

    public const LINKABLE_STATUSES = [self::STATUS_UNMATCHED, self::STATUS_AMBIGUOUS, self::STATUS_IGNORED];

    private const LEGAL_FORMS = ['bv', 'nv', 'vof', 'ltd', 'inc', 'gmbh', 'llc', 'bvba', 'sarl'];

    private const MAX_WEAK_CANDIDATES = 10;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'processed_at' => 'datetime',
            'candidate_invoice_ids' => 'array',
            'amount' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopeWhereCompany($query)
    {
        $query->where('bank_transactions.company_id', request()->header('company'));
    }

    public function scopeWhereStatus($query, $status)
    {
        if ($status === 'review') {
            return $query->whereIn('bank_transactions.status', self::OPEN_STATUSES);
        }

        return $query->where('bank_transactions.status', $status);
    }

    public function scopeWhereSearch($query, $search)
    {
        foreach (explode(' ', $search) as $term) {
            $query->where(function ($query) use ($term) {
                $query->where('counterparty_name', 'LIKE', '%'.$term.'%')
                    ->orWhere('description', 'LIKE', '%'.$term.'%');
            });
        }
    }

    public function scopeApplyFilters($query, array $filters)
    {
        $filters = collect($filters);

        if ($filters->get('status')) {
            $query->whereStatus($filters->get('status'));
        }

        if ($filters->get('search')) {
            $query->whereSearch($filters->get('search'));
        }

        $query->orderBy('booking_date', 'desc')->orderBy('id', 'desc');
    }

    public function scopePaginateData($query, $limit)
    {
        if ($limit == 'all') {
            return $query->get();
        }

        return $query->paginate($limit);
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function candidateInvoices(): Collection
    {
        return Invoice::whereIn('id', $this->candidate_invoice_ids ?? [])
            ->where('company_id', $this->company_id)
            ->with('customer')
            ->get();
    }

    /**
     * Store a parsed transaction and match it, or return null when the
     * company already has a transaction with the same fingerprint.
     *
     * @param  array<string, mixed>  $data
     */
    public static function importParsed(BankStatementImport $import, array $data, User $user): ?self
    {
        return DB::transaction(function () use ($import, $data, $user) {
            try {
                $transaction = DB::transaction(function () use ($import, $data) {
                    if (self::where('company_id', $import->company_id)->where('fingerprint', $data['fingerprint'])->exists()) {
                        return null;
                    }

                    return self::create(array_merge($data, [
                        'company_id' => $import->company_id,
                        'bank_statement_import_id' => $import->id,
                        'status' => self::STATUS_UNMATCHED,
                    ]));
                });
            } catch (UniqueConstraintViolationException) {
                // The same statement is being uploaded concurrently.
                return null;
            }

            $transaction?->autoMatch($user);

            return $transaction;
        });
    }

    /**
     * Match the transaction to the open invoices of its company on invoice
     * number, amount and counterparty name. Only a certain match is booked.
     */
    public function autoMatch(User $user): void
    {
        $pool = $this->openInvoicesQuery()->orderBy('due_date')->orderBy('id')->get();

        $referenced = $pool->filter(
            fn (Invoice $invoice) => self::descriptionContainsInvoiceNumber($this->description, $invoice->invoice_number)
        )->values();

        if ($referenced->count() === 1) {
            $invoice = $referenced->first();

            if ($this->amountMatches($invoice)) {
                $this->registerPayments($user, [$invoice], self::STATUS_MATCHED, 'invoice_number_and_amount');
            } elseif ($this->nameMatches($invoice)) {
                $this->registerPayments($user, [$invoice], self::STATUS_MATCHED, 'invoice_number_and_name');
            } else {
                $this->markForReview(self::STATUS_AMBIGUOUS, 'invoice_number_only', [$invoice->id]);
            }

            return;
        }

        if ($referenced->count() > 1) {
            $this->markForReview(self::STATUS_AMBIGUOUS, 'multiple_invoice_numbers', $referenced->pluck('id')->all());

            return;
        }

        $exact = $pool->filter(fn (Invoice $invoice) => $this->amountMatches($invoice) && $this->nameMatches($invoice))->values();

        if ($exact->count() === 1) {
            $this->registerPayments($user, [$exact->first()], self::STATUS_MATCHED, 'amount_and_name');

            return;
        }

        if ($exact->count() > 1) {
            $this->markForReview(self::STATUS_AMBIGUOUS, 'multiple_amount_and_name', $exact->pluck('id')->all());

            return;
        }

        $weak = $pool->filter(fn (Invoice $invoice) => $this->amountMatches($invoice) || $this->nameMatches($invoice))
            ->take(self::MAX_WEAK_CANDIDATES)
            ->values();

        if ($weak->isNotEmpty()) {
            $this->markForReview(self::STATUS_AMBIGUOUS, 'amount_or_name_only', $weak->pluck('id')->all());

            return;
        }

        $this->markForReview(self::STATUS_UNMATCHED, 'no_match', []);
    }

    /**
     * Record payments for this transaction on the given invoices, in order.
     * Whatever remains after paying the invoices is recorded as customer credit.
     *
     * @param  array<int, Invoice>|Collection<int, Invoice>  $invoices
     */
    public function registerPayments(User $user, array|Collection $invoices, string $status, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $invoices, $status, $reason) {
            $current = self::lockForUpdate()->find($this->id);

            if (! $current || $current->payments()->exists() || in_array($current->status, [self::STATUS_MATCHED, self::STATUS_LINKED], true)) {
                return;
            }

            $remaining = $this->amount;
            $lastCustomer = null;
            $lastInvoice = null;
            $invoiceIds = [];

            foreach ($invoices as $invoice) {
                $invoice = Invoice::with('customer')->lockForUpdate()->find($invoice->id);

                $customer = $invoice ? self::customerOf($invoice) : null;

                if (! $invoice || ! $customer) {
                    continue;
                }

                $lastCustomer = $customer;
                $lastInvoice = $invoice;
                $invoiceIds[] = $invoice->id;
                $applied = min($remaining, (int) $invoice->due_amount);

                if ($applied <= 0) {
                    continue;
                }

                Payment::createFromBankTransaction($this, $customer, $invoice, $applied, $user);
                $remaining -= $applied;
            }

            if ($lastInvoice === null || $lastCustomer === null) {
                return;
            }

            if ($remaining > 0) {
                $exchangeRate = (string) $lastCustomer->currency_id === (string) CompanySetting::getSetting('currency', $this->company_id)
                    ? 1.0
                    : (float) $lastInvoice->exchange_rate;

                Payment::createFromBankTransaction($this, $lastCustomer, null, $remaining, $user, $exchangeRate);
            }

            $this->update([
                'status' => $status,
                'match_reason' => $reason,
                'candidate_invoice_ids' => $invoiceIds,
                'processed_by' => $user->id,
                'processed_at' => now(),
            ]);
        });
    }

    /**
     * Link the transaction to invoices chosen by the user, booked in the given order.
     *
     * @param  array<int, int|string>  $invoiceIds
     */
    public function linkToInvoices(array $invoiceIds, User $user): void
    {
        $invoiceIds = array_values(array_map('intval', $invoiceIds));

        $invoices = Invoice::whereIn('id', $invoiceIds)
            ->where('company_id', $this->company_id)
            ->get()
            ->sortBy(fn (Invoice $invoice) => array_search($invoice->id, $invoiceIds, true))
            ->values();

        $this->registerPayments($user, $invoices, self::STATUS_LINKED, 'manual');
    }

    public function ignore(User $user): void
    {
        $this->update([
            'status' => self::STATUS_IGNORED,
            'processed_by' => $user->id,
            'processed_at' => now(),
        ]);
    }

    public static function descriptionContainsInvoiceNumber(?string $description, ?string $invoiceNumber): bool
    {
        $invoiceNumber = trim((string) $invoiceNumber);

        if ($description === null || $description === '' || mb_strlen($invoiceNumber) < 3) {
            return false;
        }

        $pattern = '';
        $previousWasSeparator = false;

        foreach (mb_str_split($invoiceNumber) as $character) {
            if (preg_match('/^[\p{L}\p{N}]$/u', $character)) {
                $pattern .= preg_quote($character, '/');
                $previousWasSeparator = false;
            } elseif (! $previousWasSeparator) {
                $pattern .= '[\s\-_\/.]*';
                $previousWasSeparator = true;
            }
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![0-9])/iu', $description);
    }

    public static function normalizeName(?string $name): string
    {
        $name = str_replace('.', '', mb_strtolower((string) $name));

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_filter($tokens, fn (string $token) => ! in_array($token, self::LEGAL_FORMS, true)));
    }

    public static function namesMatch(?string $first, ?string $second): bool
    {
        $first = self::normalizeName($first);
        $second = self::normalizeName($second);

        if ($first === '' || $second === '') {
            return false;
        }

        if ($first === $second) {
            return true;
        }

        $shortest = mb_strlen($first) <= mb_strlen($second) ? $first : $second;
        $longest = $shortest === $first ? $second : $first;

        return mb_strlen($shortest) >= 4 && str_contains($longest, $shortest);
    }

    /**
     * @return Builder<Invoice>
     */
    private function openInvoicesQuery(): Builder
    {
        return Invoice::where('company_id', $this->company_id)
            ->whereIn('paid_status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIALLY_PAID])
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->where('due_amount', '>', 0)
            ->whereHas('currency', fn ($query) => $query->where('code', $this->currency_code))
            ->with('customer');
    }

    private function amountMatches(Invoice $invoice): bool
    {
        return $this->amount === (int) $invoice->due_amount;
    }

    private function nameMatches(Invoice $invoice): bool
    {
        $customer = self::customerOf($invoice);

        if (! $customer) {
            return false;
        }

        return self::namesMatch($this->counterparty_name, $customer->name)
            || self::namesMatch($this->counterparty_name, $customer->company_name);
    }

    private static function customerOf(Invoice $invoice): ?Customer
    {
        $customer = $invoice->customer;

        return $customer instanceof Customer ? $customer : null;
    }

    /**
     * @param  list<int>  $candidateIds
     */
    private function markForReview(string $status, string $reason, array $candidateIds): void
    {
        $this->update([
            'status' => $status,
            'match_reason' => $reason,
            'candidate_invoice_ids' => $candidateIds,
        ]);
    }
}

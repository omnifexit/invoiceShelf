<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreditNoteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'invoice_id' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')->where('company_id', $this->header('company')),
                function (string $attribute, mixed $value, Closure $fail) {
                    $invoice = $this->findInvoice();

                    if ($invoice && $invoice->status === Invoice::STATUS_DRAFT) {
                        $fail('A draft invoice cannot be credited.');
                    }
                },
            ],
            'credit_note_date' => [
                'required',
                'date_format:Y-m-d',
            ],
            'credit_note_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('credit_notes')->where('company_id', $this->header('company')),
            ],
            'amount' => [
                'required',
                'integer',
                'min:1',
                function (string $attribute, mixed $value, Closure $fail) {
                    $invoice = $this->findInvoice();

                    if ($invoice && (int) $value > $invoice->getCreditableAmount()) {
                        $fail('The credited amount may not exceed the remaining creditable amount of the invoice ('.$invoice->getCreditableAmount().').');
                    }
                },
            ],
            'notes' => [
                'nullable',
                'string',
                'max:65000',
            ],
        ];
    }

    public function getCreditNotePayload(): array
    {
        $invoice = Invoice::findOrFail($this->input('invoice_id'));
        $exchangeRate = (float) $invoice->exchange_rate ?: 1.0;

        return collect($this->validated())
            ->merge([
                'company_id' => $this->header('company'),
                'creator_id' => $this->user()->id,
                'customer_id' => $invoice->customer_id,
                'currency_id' => $invoice->currency_id,
                'exchange_rate' => $exchangeRate,
                'base_amount' => (int) round((int) $this->input('amount') * $exchangeRate),
            ])
            ->toArray();
    }

    private function findInvoice(): ?Invoice
    {
        if (! is_numeric($this->input('invoice_id'))) {
            return null;
        }

        return Invoice::where('company_id', $this->header('company'))->find($this->input('invoice_id'));
    }
}

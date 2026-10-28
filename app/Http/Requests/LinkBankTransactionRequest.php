<?php

namespace App\Http\Requests;

use App\Models\BankTransaction;
use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class LinkBankTransactionRequest extends FormRequest
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
            'invoice_ids' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],
            'invoice_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('invoices', 'id')->where('company_id', $this->header('company')),
            ],
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $transaction = $this->route('bankTransaction');

                if (! $transaction instanceof BankTransaction) {
                    return;
                }

                if ((string) $transaction->company_id !== (string) $this->header('company')) {
                    $validator->errors()->add('bank_transaction', 'This bank transaction does not belong to the current company.');

                    return;
                }

                if (! in_array($transaction->status, BankTransaction::LINKABLE_STATUSES, true)) {
                    $validator->errors()->add('bank_transaction', 'This bank transaction has already been processed.');

                    return;
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $invoices = Invoice::with('currency')
                    ->whereIn('id', (array) $this->input('invoice_ids'))
                    ->where('company_id', $transaction->company_id)
                    ->get();

                foreach ($invoices as $invoice) {
                    if ($invoice->paid_status === Invoice::STATUS_PAID || (int) $invoice->due_amount <= 0) {
                        $validator->errors()->add('invoice_ids', "Invoice {$invoice->invoice_number} has already been paid.");
                    } elseif ($invoice->currency?->getAttribute('code') !== $transaction->currency_code) {
                        $validator->errors()->add('invoice_ids', "Invoice {$invoice->invoice_number} is not in {$transaction->currency_code}.");
                    }
                }
            },
        ];
    }
}

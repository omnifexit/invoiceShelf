<?php

namespace App\Http\Requests;

use App\Models\BankTransaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IgnoreBankTransactionRequest extends FormRequest
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
        return [];
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
                } elseif (! in_array($transaction->status, BankTransaction::OPEN_STATUSES, true)) {
                    $validator->errors()->add('bank_transaction', 'This bank transaction has already been processed.');
                }
            },
        ];
    }
}

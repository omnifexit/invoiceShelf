<?php

namespace App\Rules;

use App\Models\Customer;
use App\Models\Invoice;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WithinCustomerCreditLimit implements ValidationRule
{
    public function __construct(protected mixed $customerId = null) {}

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($this->customerId) || ! is_numeric($value)) {
            return;
        }

        $customer = Customer::find($this->customerId);

        if (! $customer || $customer->credit_limit === null) {
            return;
        }

        $outstanding = (int) Invoice::where('customer_id', $customer->id)->sum('due_amount');

        if ($outstanding + (int) round((float) $value) > $customer->credit_limit) {
            $fail('validation.credit_limit_exceeded')->translate();
        }
    }
}

<?php

namespace App\Rules;

use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class DueDateNotBeforeInvoiceDate implements ValidationRule
{
    public function __construct(protected mixed $invoiceDate = null) {}

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value) || blank($this->invoiceDate) || ! is_string($value) || ! is_string($this->invoiceDate)) {
            return;
        }

        try {
            $dueDate = Carbon::parse($value)->startOfDay();
            $invoiceDate = Carbon::parse($this->invoiceDate)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($dueDate->lt($invoiceDate)) {
            $fail('validation.due_date_before_invoice_date')->translate();
        }
    }
}

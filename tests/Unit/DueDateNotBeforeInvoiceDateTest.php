<?php

use App\Rules\DueDateNotBeforeInvoiceDate;
use Illuminate\Support\Facades\Validator;

function validateDueDate(mixed $dueDate, mixed $invoiceDate): bool
{
    return Validator::make(
        ['due_date' => $dueDate],
        ['due_date' => [new DueDateNotBeforeInvoiceDate($invoiceDate)]]
    )->passes();
}

test('due date before invoice date fails', function () {
    expect(validateDueDate('2026-10-27', '2026-10-28'))->toBeFalse();
});

test('due date equal to invoice date passes', function () {
    expect(validateDueDate('2026-10-28', '2026-10-28'))->toBeTrue();
});

test('due date equal to invoice date with time passes', function () {
    expect(validateDueDate('2026-10-28', '2026-10-28 23:59'))->toBeTrue();
});

test('due date after invoice date passes', function () {
    expect(validateDueDate('2026-10-29', '2026-10-28'))->toBeTrue();
});

test('empty due date passes', function () {
    expect(validateDueDate(null, '2026-10-28'))->toBeTrue();
    expect(validateDueDate('', '2026-10-28'))->toBeTrue();
});

test('missing invoice date is ignored', function () {
    expect(validateDueDate('2026-10-28', null))->toBeTrue();
});

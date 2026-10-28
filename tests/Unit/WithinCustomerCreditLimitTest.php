<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Rules\WithinCustomerCreditLimit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

function validateCreditLimit(mixed $total, mixed $customerId): bool
{
    return Validator::make(
        ['total' => $total],
        ['total' => [new WithinCustomerCreditLimit($customerId)]]
    )->passes();
}

test('customer without credit limit always passes', function () {
    $customer = Customer::factory()->create(['credit_limit' => null]);
    Invoice::factory()->create(['customer_id' => $customer->id, 'total' => 50000, 'due_amount' => 50000]);

    expect(validateCreditLimit(999999999, $customer->id))->toBeTrue();
});

test('total equal to the credit limit passes', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);

    expect(validateCreditLimit(1000, $customer->id))->toBeTrue();
});

test('total above the credit limit fails', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);

    expect(validateCreditLimit(1001, $customer->id))->toBeFalse();
});

test('outstanding amount is added to the new total', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    Invoice::factory()->create(['customer_id' => $customer->id, 'total' => 600, 'due_amount' => 600]);

    expect(validateCreditLimit(400, $customer->id))->toBeTrue();
    expect(validateCreditLimit(401, $customer->id))->toBeFalse();
});

test('paid invoices do not count towards the outstanding amount', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    Invoice::factory()->create(['customer_id' => $customer->id, 'total' => 5000, 'due_amount' => 0]);

    expect(validateCreditLimit(1000, $customer->id))->toBeTrue();
});

test('invoices of other customers do not count', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    $other = Customer::factory()->create();
    Invoice::factory()->create(['customer_id' => $other->id, 'total' => 5000, 'due_amount' => 5000]);

    expect(validateCreditLimit(1000, $customer->id))->toBeTrue();
});

test('credit limit of zero rejects any positive total', function () {
    $customer = Customer::factory()->create(['credit_limit' => 0]);

    expect(validateCreditLimit(1, $customer->id))->toBeFalse();
    expect(validateCreditLimit(0, $customer->id))->toBeTrue();
});

test('string totals are compared numerically', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);

    expect(validateCreditLimit('1000', $customer->id))->toBeTrue();
    expect(validateCreditLimit('1000.6', $customer->id))->toBeFalse();
});

test('missing or unknown customer passes', function () {
    expect(validateCreditLimit(1000, null))->toBeTrue();
    expect(validateCreditLimit(1000, 999999))->toBeTrue();
});

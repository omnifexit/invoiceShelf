<?php

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

function unitInvoice(array $attributes = []): Invoice
{
    return Invoice::factory()->sent()->create(array_merge([
        'total' => 10000,
        'tax' => 0,
        'due_amount' => 10000,
        'exchange_rate' => 1,
    ], $attributes));
}

test('credit note belongs to invoice, customer, company and currency', function () {
    $creditNote = CreditNote::factory()->create();

    foreach (['invoice', 'customer', 'company', 'currency'] as $relation) {
        expect($creditNote->$relation())->toBeInstanceOf(BelongsTo::class)
            ->and($creditNote->$relation()->exists())->toBeTrue();
    }

    expect($creditNote->customer_id)->toBe($creditNote->invoice->customer_id)
        ->and($creditNote->invoice->creditNotes()->count())->toBe(1);
});

test('a draft invoice has nothing to credit', function () {
    $invoice = Invoice::factory()->create([
        'total' => 10000,
        'due_amount' => 10000,
    ]);

    expect($invoice->getCreditableAmount())->toBe(0);
});

test('creditable amount is the uncredited total, limited to the amount due', function () {
    $invoice = unitInvoice();

    expect($invoice->getCreditableAmount())->toBe(10000);

    CreditNote::factory()->create([
        'invoice_id' => $invoice->id,
        'amount' => 4000,
    ]);

    expect($invoice->getCreditableAmount())->toBe(6000);

    $invoice->due_amount = 2000;

    expect($invoice->getCreditableAmount())->toBe(2000);
});

test('no tax is credited when the invoice has no tax', function () {
    expect(CreditNote::calculateTax(unitInvoice(), 5000))->toBe(0);
});

test('tax is credited proportionally, rounded down', function () {
    $invoice = unitInvoice(['total' => 12100, 'tax' => 2100, 'due_amount' => 12100]);

    expect(CreditNote::calculateTax($invoice, 6050))->toBe(1050)
        ->and(CreditNote::calculateTax($invoice, 3000))->toBe(520)
        ->and(CreditNote::calculateTax($invoice, 1))->toBe(0);
});

test('the credit note that completes the invoice gets the remaining tax', function () {
    $invoice = unitInvoice(['total' => 12100, 'tax' => 2100, 'due_amount' => 12100]);

    CreditNote::factory()->create(['invoice_id' => $invoice->id, 'amount' => 9050, 'tax' => 1570]);

    expect(CreditNote::calculateTax($invoice, 3050))->toBe(530)
        ->and(CreditNote::calculateTax($invoice, 12100))->toBe(530);
});

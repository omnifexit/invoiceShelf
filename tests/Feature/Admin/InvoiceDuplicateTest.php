<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\SerialNumberFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

function duplicateInvoiceRequest(Invoice $invoice)
{
    return postJson("api/v1/invoices/{$invoice->id}/clone");
}

test('duplicate creates a draft invoice dated today with the next invoice number', function () {
    $this->travelTo(Carbon::parse('2026-10-28 10:00:00'));

    $source = Invoice::factory()->create([
        'invoice_date' => '2020-01-15',
        'due_date' => '2020-02-15',
        'status' => Invoice::STATUS_SENT,
        'paid_status' => Invoice::STATUS_PAID,
        'notes' => 'Duplicate me',
        'reference_number' => 'REF-1',
    ]);

    $expectedNumber = (new SerialNumberFormatter)
        ->setModel($source)
        ->setCompany($source->company_id)
        ->setCustomer($source->customer_id)
        ->setNextNumbers()
        ->getNextNumber();

    $response = duplicateInvoiceRequest($source)
        ->assertCreated()
        ->assertJsonPath('data.status', Invoice::STATUS_DRAFT)
        ->assertJsonPath('data.paid_status', Invoice::STATUS_UNPAID)
        ->assertJsonPath('data.customer_id', $source->customer_id)
        ->assertJsonPath('data.notes', 'Duplicate me')
        ->assertJsonPath('data.reference_number', 'REF-1')
        ->assertJsonPath('data.invoice_number', $expectedNumber);

    expect($response->json('data.invoice_number'))->not->toBe($source->invoice_number);

    $duplicate = Invoice::find($response->json('data.id'));

    expect($duplicate->id)->not->toBe($source->id)
        ->and(Carbon::parse($duplicate->invoice_date)->format('Y-m-d'))->toBe('2026-10-28')
        ->and($duplicate->sequence_number)->toBeGreaterThan($source->sequence_number)
        ->and($duplicate->creator_id)->toBe(1)
        ->and($duplicate->company_id)->toBe($source->company_id)
        ->and($duplicate->recurring_invoice_id)->toBeNull()
        ->and($duplicate->unique_hash)->not->toBeEmpty();
});

test('duplicate copies totals and currency', function () {
    $source = Invoice::factory()->create();

    $response = duplicateInvoiceRequest($source)->assertCreated();

    $duplicate = Invoice::find($response->json('data.id'));

    expect($duplicate->sub_total)->toBe($source->sub_total)
        ->and($duplicate->total)->toBe($source->total)
        ->and($duplicate->tax)->toBe($source->tax)
        ->and($duplicate->discount)->toEqual($source->discount)
        ->and($duplicate->discount_val)->toBe($source->discount_val)
        ->and($duplicate->currency_id)->toBe($source->currency_id)
        ->and($duplicate->exchange_rate)->toEqual($source->exchange_rate)
        ->and($duplicate->tax_per_item)->toBe($source->tax_per_item)
        ->and($duplicate->discount_per_item)->toBe($source->discount_per_item)
        ->and($duplicate->due_amount)->toBe($source->total);
});

test('duplicate copies invoice items with their taxes', function () {
    $source = Invoice::factory()->create();

    $firstItem = InvoiceItem::factory()->create([
        'invoice_id' => $source->id,
        'recurring_invoice_id' => null,
    ]);
    $secondItem = InvoiceItem::factory()->create([
        'invoice_id' => $source->id,
        'recurring_invoice_id' => null,
    ]);

    $tax = Tax::factory()->create(['invoice_item_id' => $firstItem->id]);
    $zeroTax = Tax::factory()->create(['invoice_item_id' => $firstItem->id, 'amount' => 0]);

    $response = duplicateInvoiceRequest($source)->assertCreated();

    $duplicate = Invoice::find($response->json('data.id'));
    $duplicateItems = $duplicate->items()->with('taxes')->orderBy('id')->get();

    expect($duplicateItems)->toHaveCount(2);

    foreach ([$firstItem, $secondItem] as $index => $sourceItem) {
        $duplicateItem = $duplicateItems[$index];

        expect($duplicateItem->id)->not->toBe($sourceItem->id)
            ->and($duplicateItem->invoice_id)->toBe($duplicate->id)
            ->and($duplicateItem->recurring_invoice_id)->toBeNull()
            ->and($duplicateItem->name)->toBe($sourceItem->name)
            ->and($duplicateItem->description)->toBe($sourceItem->description)
            ->and($duplicateItem->quantity)->toEqual($sourceItem->quantity)
            ->and($duplicateItem->price)->toEqual($sourceItem->price)
            ->and($duplicateItem->total)->toEqual($sourceItem->total)
            ->and($duplicateItem->item_id)->toBe($sourceItem->item_id);
    }

    $duplicateTaxes = $duplicateItems[0]->taxes->sortBy('id')->values();

    expect($duplicateTaxes)->toHaveCount(2)
        ->and($duplicateItems[1]->taxes)->toHaveCount(0);

    foreach ([$tax, $zeroTax] as $index => $sourceTax) {
        $duplicateTax = $duplicateTaxes[$index];

        expect($duplicateTax->id)->not->toBe($sourceTax->id)
            ->and($duplicateTax->invoice_item_id)->toBe($duplicateItems[0]->id)
            ->and($duplicateTax->tax_type_id)->toBe($sourceTax->tax_type_id)
            ->and($duplicateTax->percent)->toEqual($sourceTax->percent)
            ->and($duplicateTax->amount)->toEqual($sourceTax->amount);
    }
});

test('duplicate copies invoice level taxes', function () {
    $source = Invoice::factory()->create();
    $tax = Tax::factory()->create(['invoice_id' => $source->id]);

    $response = duplicateInvoiceRequest($source)->assertCreated();

    $duplicate = Invoice::find($response->json('data.id'));
    $duplicateTaxes = $duplicate->taxes()->get();

    expect($duplicateTaxes)->toHaveCount(1);

    $duplicateTax = $duplicateTaxes->first();

    expect($duplicateTax->id)->not->toBe($tax->id)
        ->and($duplicateTax->invoice_id)->toBe($duplicate->id)
        ->and($duplicateTax->tax_type_id)->toBe($tax->tax_type_id)
        ->and($duplicateTax->amount)->toEqual($tax->amount)
        ->and($duplicateTax->percent)->toEqual($tax->percent);
});

test('duplicate leaves the original invoice untouched', function () {
    $source = Invoice::factory()->create(['status' => Invoice::STATUS_SENT]);
    $item = InvoiceItem::factory()->create([
        'invoice_id' => $source->id,
        'recurring_invoice_id' => null,
    ]);
    Tax::factory()->create(['invoice_item_id' => $item->id]);
    Tax::factory()->create(['invoice_id' => $source->id]);

    $originalNumber = $source->invoice_number;
    $originalDate = Carbon::parse($source->invoice_date)->format('Y-m-d');
    $invoiceCount = Invoice::count();

    duplicateInvoiceRequest($source)->assertCreated();

    $source->refresh();

    expect(Invoice::count())->toBe($invoiceCount + 1)
        ->and($source->invoice_number)->toBe($originalNumber)
        ->and($source->status)->toBe(Invoice::STATUS_SENT)
        ->and(Carbon::parse($source->invoice_date)->format('Y-m-d'))->toBe($originalDate)
        ->and($source->items()->count())->toBe(1)
        ->and($source->taxes()->count())->toBe(1)
        ->and($item->taxes()->count())->toBe(1);
});

test('duplicate is rejected for an invoice of another company', function () {
    $otherCompany = Company::factory()->create();
    $user = User::find(1);

    if ($user->hasCompany($otherCompany->id)) {
        $user->companies()->detach($otherCompany->id);
    }

    $source = Invoice::factory()->create(['company_id' => $otherCompany->id]);
    $invoiceCount = Invoice::count();

    duplicateInvoiceRequest($source)->assertForbidden();

    expect(Invoice::count())->toBe($invoiceCount);
});

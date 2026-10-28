<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

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

function creditLimitInvoicePayload(Customer $customer, int $total): array
{
    return Invoice::factory()->raw([
        'customer_id' => $customer->id,
        'total' => $total,
        'sub_total' => $total,
        'items' => [InvoiceItem::factory()->raw()],
        'taxes' => [Tax::factory()->raw()],
    ]);
}

test('creates invoice for customer without credit limit', function () {
    $customer = Customer::factory()->create(['credit_limit' => null]);
    Invoice::factory()->create(['customer_id' => $customer->id, 'total' => 900000, 'due_amount' => 900000]);

    $payload = creditLimitInvoicePayload($customer, 500000);

    postJson('api/v1/invoices', $payload)->assertOk();

    $this->assertDatabaseHas('invoices', [
        'invoice_number' => $payload['invoice_number'],
        'customer_id' => $customer->id,
    ]);
});

test('creates invoice within credit limit', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    Invoice::factory()->create(['customer_id' => $customer->id, 'total' => 400, 'due_amount' => 400]);

    $payload = creditLimitInvoicePayload($customer, 600);

    postJson('api/v1/invoices', $payload)->assertOk();

    $this->assertDatabaseHas('invoices', ['invoice_number' => $payload['invoice_number']]);
});

test('rejects invoice exceeding credit limit', function () {
    $customer = Customer::factory()->create(['credit_limit' => 1000]);
    Invoice::factory()->create(['customer_id' => $customer->id, 'total' => 400, 'due_amount' => 400]);

    $payload = creditLimitInvoicePayload($customer, 601);

    postJson('api/v1/invoices', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['total'])
        ->assertJsonPath('errors.total.0', __('validation.credit_limit_exceeded'));

    $this->assertDatabaseMissing('invoices', ['invoice_number' => $payload['invoice_number']]);
});

test('rejects invoice when total alone exceeds credit limit', function () {
    $customer = Customer::factory()->create(['credit_limit' => 500]);

    $payload = creditLimitInvoicePayload($customer, 501);

    postJson('api/v1/invoices', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['total']);
});

test('update is not blocked by credit limit', function () {
    $customer = Customer::factory()->create(['credit_limit' => 100]);
    $invoice = Invoice::factory()->create(['customer_id' => $customer->id, 'total' => 5000, 'due_amount' => 5000]);

    $payload = creditLimitInvoicePayload($customer, 6000);

    putJson("api/v1/invoices/{$invoice->id}", $payload)->assertOk();
});

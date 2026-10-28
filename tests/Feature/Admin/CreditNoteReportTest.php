<?php

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->company = Company::find($user->companies()->first()->id);
    $this->withHeaders([
        'company' => $this->company->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

function reportInvoice(string $date, int $total = 10000, int $tax = 0): Invoice
{
    return Invoice::factory()->sent()->create([
        'invoice_date' => $date,
        'sub_total' => $total - $tax,
        'total' => $total,
        'tax' => $tax,
        'due_amount' => $total,
        'base_sub_total' => $total - $tax,
        'base_total' => $total,
        'base_tax' => $tax,
        'base_due_amount' => $total,
        'exchange_rate' => 1,
        'paid_status' => Invoice::STATUS_UNPAID,
        'sent' => true,
    ]);
}

function reportCredit(Invoice $invoice, int $amount, string $date, string $number)
{
    return postJson('api/v1/credit-notes', [
        'invoice_id' => $invoice->id,
        'credit_note_date' => $date,
        'credit_note_number' => $number,
        'amount' => $amount,
    ])->assertOk();
}

test('dashboard subtracts credit notes from sales', function () {
    CompanySetting::setSettings(['fiscal_year' => '1-12'], $this->company->id);
    $today = now()->format('Y-m-d');

    $before = getJson('api/v1/dashboard')->assertOk()->json();

    reportInvoice($today);

    $withInvoice = getJson('api/v1/dashboard')->assertOk()->json();
    expect($withInvoice['total_sales'] - $before['total_sales'])->toEqual(10000);

    $invoice = Invoice::latest('id')->first();
    reportCredit($invoice, 2500, $today, 'CN-DASH-1');

    $after = getJson('api/v1/dashboard')->assertOk()->json();

    expect($after['total_sales'])->toEqual($withInvoice['total_sales'] - 2500)
        ->and($after['total_credited'])->toBeGreaterThanOrEqual(2500)
        ->and($after['total_credited'] - $withInvoice['total_credited'])->toEqual(2500)
        ->and($after['total_amount_due'])->toEqual($withInvoice['total_amount_due'] - 2500)
        ->and($after['total_credit_note_count'])->toBe($withInvoice['total_credit_note_count'] + 1)
        ->and(array_sum($after['chart_data']['invoice_totals']))
        ->toEqual(array_sum($withInvoice['chart_data']['invoice_totals']) - 2500);
});

test('customer sales report nets credit notes', function () {
    $invoice = reportInvoice('2099-01-10');
    reportCredit($invoice, 3000, '2099-01-20', 'CN-REP-1');

    get('/reports/sales/customers/'.$this->company->unique_hash.'?from_date=2099-01-01&to_date=2099-01-31&preview=1')
        ->assertOk()
        ->assertViewHas('totalAmount', 7000)
        ->assertViewHas('totalCreditedAmount', 3000)
        ->assertSee('CN-REP-1');
});

test('customer sales report shows customers with only a credit note in the period', function () {
    $invoice = reportInvoice('2099-01-10');
    reportCredit($invoice, 3000, '2099-02-05', 'CN-REP-2');

    get('/reports/sales/customers/'.$this->company->unique_hash.'?from_date=2099-02-01&to_date=2099-02-28&preview=1')
        ->assertOk()
        ->assertViewHas('totalAmount', -3000)
        ->assertViewHas('totalCreditedAmount', 3000)
        ->assertSee($invoice->customer->name);
});

test('tax summary report subtracts credited tax', function () {
    $invoice = reportInvoice('2099-01-10', 12100, 2100);

    Tax::factory()->create([
        'invoice_id' => $invoice->id,
        'company_id' => $this->company->id,
        'amount' => 2100,
        'base_amount' => 2100,
    ]);

    reportCredit($invoice, 12100, '2099-01-15', 'CN-TAX-1');

    expect($invoice->refresh()->paid_status)->toBe(Invoice::STATUS_PAID);

    get('/reports/tax-summary/'.$this->company->unique_hash.'?from_date=2099-01-01&to_date=2099-01-31&preview=1')
        ->assertOk()
        ->assertViewHas('totalTaxAmount', 2100)
        ->assertViewHas('totalCreditedTaxAmount', 2100)
        ->assertViewHas('netTaxAmount', 0);
});

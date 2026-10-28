<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\get;

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

function parseInvoiceExportCsv(string $content): array
{
    $lines = array_values(array_filter(
        preg_split('/\r\n|\n|\r/', trim($content)),
        fn ($line) => $line !== ''
    ));

    return array_map(fn ($line) => str_getcsv($line, ',', '"', ''), $lines);
}

function exportInvoiceRows(string $query = ''): array
{
    $response = get('api/v1/invoices/export'.($query !== '' ? '?'.$query : ''))->assertOk();

    $rows = parseInvoiceExportCsv($response->streamedContent());
    array_shift($rows);

    return $rows;
}

test('export returns csv download with header row', function () {
    $response = get('api/v1/invoices/export')->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('.csv');

    $rows = parseInvoiceExportCsv($response->streamedContent());

    expect($rows[0])->toBe([
        'Invoice Number',
        'Customer Name',
        'Invoice Date',
        'Due Date',
        'Total',
        'Status',
    ]);
});

test('export contains the expected columns per invoice', function () {
    $customer = Customer::factory()->create(['name' => 'Export Klant BV']);
    $invoice = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_date' => '2026-01-10',
        'due_date' => '2026-02-10',
        'total' => 12345,
        'status' => Invoice::STATUS_SENT,
    ]);

    $rows = exportInvoiceRows('customer_id='.$customer->id);

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toBe([
        $invoice->invoice_number,
        'Export Klant BV',
        '2026-01-10',
        '2026-02-10',
        '123.45',
        'SENT',
    ]);
});

test('export respects status filter', function () {
    $customer = Customer::factory()->create();
    $draft = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'status' => Invoice::STATUS_DRAFT,
    ]);
    $sent = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'status' => Invoice::STATUS_SENT,
    ]);

    $numbers = array_column(exportInvoiceRows('customer_id='.$customer->id.'&status=DRAFT'), 0);

    expect($numbers)->toBe([$draft->invoice_number])
        ->not->toContain($sent->invoice_number);
});

test('export respects overdue filter', function () {
    $customer = Customer::factory()->create();
    $overdue = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_date' => '1988-07-18',
        'due_date' => '1988-08-18',
        'overdue' => true,
    ]);
    $notOverdue = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_date' => '1988-07-18',
        'due_date' => '1988-08-18',
        'overdue' => false,
    ]);

    $numbers = array_column(exportInvoiceRows('customer_id='.$customer->id.'&overdue=1'), 0);

    expect($numbers)->toBe([$overdue->invoice_number])
        ->not->toContain($notOverdue->invoice_number);
});

test('export respects date range filter', function () {
    $customer = Customer::factory()->create();
    $january = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_date' => '2026-01-15',
        'due_date' => '2026-02-15',
    ]);
    $march = Invoice::factory()->create([
        'customer_id' => $customer->id,
        'invoice_date' => '2026-03-15',
        'due_date' => '2026-04-15',
    ]);

    $numbers = array_column(
        exportInvoiceRows('customer_id='.$customer->id.'&from_date=2026-01-01&to_date=2026-01-31'),
        0
    );

    expect($numbers)->toBe([$january->invoice_number])
        ->not->toContain($march->invoice_number);
});

test('export respects invoice number filter', function () {
    $invoiceA = Invoice::factory()->create();
    $invoiceB = Invoice::factory()->create();

    $numbers = array_column(exportInvoiceRows('invoice_number='.urlencode($invoiceA->invoice_number)), 0);

    expect($numbers)->toContain($invoiceA->invoice_number)
        ->not->toContain($invoiceB->invoice_number);
});

test('export only contains invoices of the current company', function () {
    $companyId = User::find(1)->companies()->first()->id;
    $other = Company::factory()->create();
    $foreign = Invoice::factory()->create([
        'company_id' => $other->id,
        'invoice_number' => 'FOREIGN-0001',
    ]);

    $rows = exportInvoiceRows();

    expect(array_column($rows, 0))->not->toContain($foreign->invoice_number);
    expect($rows)->toHaveCount(Invoice::where('company_id', $companyId)->count());
});

test('export neutralises spreadsheet formulas in text fields', function () {
    $customer = Customer::factory()->create(['name' => '=HYPERLINK("http://evil")']);
    Invoice::factory()->create(['customer_id' => $customer->id]);

    $rows = exportInvoiceRows('customer_id='.$customer->id);

    expect($rows)->toHaveCount(1);
    expect($rows[0][1])->toBe('\'=HYPERLINK("http://evil")');
});

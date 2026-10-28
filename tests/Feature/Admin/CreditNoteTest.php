<?php

use App\Http\Controllers\V1\Admin\CreditNote\CreditNotesController;
use App\Http\Requests\CreditNoteRequest;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
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
    $this->companyId = $user->companies()->first()->id;
    $this->withHeaders([
        'company' => $this->companyId,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

function sentInvoice(int $total = 10000, int $tax = 0, array $attributes = []): Invoice
{
    return Invoice::factory()->sent()->create(array_merge([
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
    ], $attributes));
}

function creditInvoice(Invoice $invoice, int $amount, array $data = [])
{
    static $number = 0;
    $number++;

    return postJson('api/v1/credit-notes', array_merge([
        'invoice_id' => $invoice->id,
        'credit_note_date' => now()->format('Y-m-d'),
        'credit_note_number' => 'CN-TEST-'.$number,
        'amount' => $amount,
        'notes' => 'Returned goods',
    ], $data));
}

test('list credit notes', function () {
    creditInvoice(sentInvoice(), 1000)->assertOk();

    getJson('api/v1/credit-notes?page=1')
        ->assertOk()
        ->assertJsonPath('meta.credit_note_total_count', 1)
        ->assertJsonCount(1, 'data');
});

test('create a credit note for the full invoice', function () {
    $invoice = sentInvoice();

    $response = creditInvoice($invoice, 10000, ['credit_note_number' => null]);
    $response->assertUnprocessable()->assertJsonValidationErrors('credit_note_number');

    $nextNumber = getJson('api/v1/next-number?key=credit_note')->json('nextNumber');

    creditInvoice($invoice, 10000, ['credit_note_number' => $nextNumber])
        ->assertOk()
        ->assertJsonPath('data.amount', 10000)
        ->assertJsonPath('data.invoice_id', $invoice->id);

    expect($nextNumber)->toStartWith('CN-');

    $invoice->refresh();
    expect((int) $invoice->due_amount)->toBe(0)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID)
        ->and($invoice->status)->toBe(Invoice::STATUS_COMPLETED);

    $this->assertDatabaseHas('credit_notes', [
        'invoice_id' => $invoice->id,
        'amount' => 10000,
        'company_id' => $this->companyId,
        'customer_id' => $invoice->customer_id,
    ]);
});

test('crediting an issued invoice never turns it back into a draft', function () {
    $invoice = sentInvoice(10000, 0, ['sent' => false]);

    creditInvoice($invoice, 2500)->assertOk();

    $invoice->refresh();
    expect($invoice->status)->toBe(Invoice::STATUS_SENT)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PARTIALLY_PAID);

    creditInvoice($invoice, 2500)->assertOk();
});

test('partial credit note lowers the amount due', function () {
    $invoice = sentInvoice();

    creditInvoice($invoice, 2500)->assertOk();

    $invoice->refresh();
    expect((int) $invoice->due_amount)->toBe(7500)
        ->and((int) $invoice->base_due_amount)->toBe(7500)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($invoice->status)->toBe(Invoice::STATUS_SENT);
});

test('multiple credit notes can credit the whole invoice but not more', function () {
    $invoice = sentInvoice();

    creditInvoice($invoice, 3000)->assertOk();
    creditInvoice($invoice, 4000)->assertOk();
    creditInvoice($invoice, 3000)->assertOk();

    $invoice->refresh();
    expect((int) $invoice->due_amount)->toBe(0)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID)
        ->and((int) $invoice->creditNotes()->sum('amount'))->toBe(10000);

    creditInvoice($invoice, 1)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect($invoice->creditNotes()->count())->toBe(3);
});

test('a single credit note cannot exceed the invoice total', function () {
    $invoice = sentInvoice();

    creditInvoice($invoice, 10001)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect((int) $invoice->refresh()->due_amount)->toBe(10000);
    $this->assertDatabaseCount('credit_notes', 0);
});

test('credit notes together cannot exceed the invoice total', function () {
    $invoice = sentInvoice();

    creditInvoice($invoice, 6000)->assertOk();

    creditInvoice($invoice, 5000)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    expect((int) $invoice->refresh()->due_amount)->toBe(4000)
        ->and((int) $invoice->creditNotes()->sum('amount'))->toBe(6000);
});

test('the creditable amount is limited to the open amount after a payment', function () {
    $invoice = sentInvoice();

    postJson('api/v1/payments', [
        'invoice_id' => $invoice->id,
        'customer_id' => $invoice->customer_id,
        'payment_date' => now()->format('Y-m-d'),
        'payment_number' => 'PAY-CN-1',
        'amount' => 8000,
        'exchange_rate' => 1,
        'payment_method_id' => 1,
    ])->assertOk();

    expect((int) $invoice->refresh()->due_amount)->toBe(2000);

    creditInvoice($invoice, 3000)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    creditInvoice($invoice, 2000)->assertOk();

    $invoice->refresh();
    expect((int) $invoice->due_amount)->toBe(0)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID);
});

test('credit notes are numbered sequentially and numbers are unique per company', function () {
    $invoice = sentInvoice();

    $first = creditInvoice($invoice, 1000)->assertOk()->json('data');
    $second = creditInvoice($invoice, 1000)->assertOk()->json('data');

    expect($second['sequence_number'])->toBe($first['sequence_number'] + 1)
        ->and($second['credit_note_number'])->not->toBe($first['credit_note_number']);

    creditInvoice($invoice, 1000, ['credit_note_number' => $first['credit_note_number']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('credit_note_number');
});

test('the tax part is split proportionally and adds up to the invoice tax', function () {
    $invoice = sentInvoice(12100, 2100);

    creditInvoice($invoice, 6050)->assertOk()->assertJsonPath('data.tax', 1050);
    creditInvoice($invoice, 3000)->assertOk()->assertJsonPath('data.tax', intdiv(3000 * 2100, 12100));
    creditInvoice($invoice, 3050)->assertOk()->assertJsonPath('data.tax', 2100 - 1050 - 520);

    expect((int) $invoice->creditNotes()->sum('tax'))->toBe(2100)
        ->and((int) $invoice->creditNotes()->sum('base_tax'))->toBe(2100);
});

test('a draft invoice cannot be credited', function () {
    $invoice = Invoice::factory()->create([
        'total' => 10000,
        'due_amount' => 10000,
        'exchange_rate' => 1,
    ]);

    creditInvoice($invoice, 1000)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_id');
});

test('an invoice of another company cannot be credited', function () {
    $company = Company::factory()->create();
    $invoice = sentInvoice(10000, 0, ['company_id' => $company->id]);

    creditInvoice($invoice, 1000)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_id');
});

test('credit note validation', function () {
    postJson('api/v1/credit-notes', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invoice_id', 'credit_note_date', 'credit_note_number', 'amount']);

    $invoice = sentInvoice();

    creditInvoice($invoice, 0)->assertUnprocessable()->assertJsonValidationErrors('amount');
    creditInvoice($invoice, -5)->assertUnprocessable()->assertJsonValidationErrors('amount');
    creditInvoice($invoice, 0, ['amount' => 12.5])->assertUnprocessable()->assertJsonValidationErrors('amount');

    $this->assertDatabaseCount('credit_notes', 0);
});

test('show credit note', function () {
    $invoice = sentInvoice();
    $id = creditInvoice($invoice, 1000)->json('data.id');

    getJson("api/v1/credit-notes/{$id}")
        ->assertOk()
        ->assertJsonPath('data.invoice.id', $invoice->id)
        ->assertJsonPath('data.customer.id', $invoice->customer_id)
        ->assertJsonPath('data.credit_note_pdf_url', CreditNote::find($id)->creditNotePdfUrl);
});

test('deleting a credit note restores the amount due', function () {
    $invoice = sentInvoice();
    $id = creditInvoice($invoice, 2500)->json('data.id');

    postJson('api/v1/credit-notes/delete', ['ids' => [$id]])
        ->assertOk()
        ->assertJson(['success' => true]);

    $invoice->refresh();
    expect((int) $invoice->due_amount)->toBe(10000)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_UNPAID);

    $this->assertModelMissing(CreditNote::make(['id' => $id]));
});

test('deleting one of multiple credit notes reopens the invoice', function () {
    $invoice = sentInvoice();

    creditInvoice($invoice, 4000)->assertOk();
    $id = creditInvoice($invoice, 6000)->json('data.id');

    $invoice->refresh();
    expect((int) $invoice->due_amount)->toBe(0)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID);

    postJson('api/v1/credit-notes/delete', ['ids' => [$id]])->assertOk();

    $invoice->refresh();
    expect((int) $invoice->due_amount)->toBe(6000)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($invoice->status)->toBe(Invoice::STATUS_SENT);
});

test('an invoice with credit notes cannot be deleted', function () {
    $invoice = sentInvoice();
    creditInvoice($invoice, 1000)->assertOk();

    postJson('api/v1/invoices/delete', ['ids' => [$invoice->id]])
        ->assertUnprocessable();

    $this->assertModelExists($invoice);
});

test('credit note pdf preview', function () {
    $invoice = sentInvoice();
    $creditNote = CreditNote::find(creditInvoice($invoice, 1000)->json('data.id'));

    get('/credit-notes/pdf/'.$creditNote->unique_hash.'?preview=1')
        ->assertOk()
        ->assertSee($creditNote->credit_note_number)
        ->assertSee($invoice->invoice_number);
});

test('credit note pdf stream', function () {
    $creditNote = CreditNote::find(creditInvoice(sentInvoice(), 1000)->json('data.id'));

    $response = get('/credit-notes/pdf/'.$creditNote->unique_hash);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

test('filter credit notes by invoice', function () {
    $invoice = sentInvoice();
    $otherInvoice = sentInvoice();

    creditInvoice($invoice, 1000)->assertOk();
    creditInvoice($otherInvoice, 1000)->assertOk();
    creditInvoice($otherInvoice, 1000)->assertOk();

    $response = getJson("api/v1/credit-notes?invoice_id={$invoice->id}")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.invoice_id'))->toBe($invoice->id);
});

test('next number for credit notes', function () {
    getJson('api/v1/next-number?key=credit_note')
        ->assertOk()
        ->assertJson(['success' => true])
        ->assertJsonPath('nextNumber', 'CN-000001');
});

test('store validates using a form request', function () {
    $this->assertActionUsesFormRequest(
        CreditNotesController::class,
        'store',
        CreditNoteRequest::class
    );
});

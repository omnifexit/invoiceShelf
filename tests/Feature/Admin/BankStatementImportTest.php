<?php

use App\Http\Controllers\V1\Admin\BankStatement\ImportBankStatementController;
use App\Http\Controllers\V1\Admin\BankStatement\LinkBankTransactionController;
use App\Http\Requests\ImportBankStatementRequest;
use App\Http\Requests\LinkBankTransactionRequest;
use App\Models\BankStatementImport;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    Queue::fake();

    $user = User::find(1);
    $this->companyId = $user->companies()->first()->id;
    $this->eur = Currency::where('code', 'EUR')->first();

    CompanySetting::setSettings(['currency' => $this->eur->id], $this->companyId);

    $this->withHeaders([
        'company' => $this->companyId,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

function bankCustomer(string $name, ?string $companyName = null, array $attributes = []): Customer
{
    return Customer::factory()->create(array_merge([
        'name' => $name,
        'company_name' => $companyName,
        'currency_id' => test()->eur->id,
        'company_id' => test()->companyId,
    ], $attributes));
}

function bankInvoice(Customer $customer, string $number, int $totalCents, string $status = Invoice::STATUS_SENT, array $attributes = []): Invoice
{
    return Invoice::factory()->create(array_merge([
        'invoice_number' => $number,
        'total' => $totalCents,
        'sub_total' => $totalCents,
        'due_amount' => $totalCents,
        'base_due_amount' => $totalCents,
        'exchange_rate' => 1,
        'currency_id' => test()->eur->id,
        'status' => $status,
        'paid_status' => Invoice::STATUS_UNPAID,
        'customer_id' => $customer->id,
        'company_id' => $customer->company_id,
    ], $attributes));
}

function bankUpload(string $fixture): TestResponse
{
    $file = UploadedFile::fake()->createWithContent(
        $fixture,
        file_get_contents(base_path('tests/Fixtures/Camt053/'.$fixture))
    );

    return postJson('api/v1/bank-statements/import', ['file' => $file]);
}

function bankTransactionFor(string $reference): BankTransaction
{
    return BankTransaction::where('bank_reference', $reference)->firstOrFail();
}

function certainMatchInvoices(): array
{
    return [
        bankInvoice(bankCustomer('Jansen B.V.'), 'INV-BANK-0001', 12100),
        bankInvoice(bankCustomer('Bakkerij De Vries'), 'INV-BANK-0002', 25000),
        bankInvoice(bankCustomer('Pietersen', 'Pietersen Consultancy'), 'INV-BANK-0003', 9995),
    ];
}

function doubtfulInvoices(): array
{
    $klaassen = bankCustomer('Klaassen');
    $smit = bankCustomer('Groothandel Smit');

    return [
        '0020' => bankInvoice(bankCustomer('Hendriks Bouw'), 'INV-BANK-0020', 30000),
        'klaassen_a' => bankInvoice($klaassen, 'INV-BANK-0030', 8000),
        'klaassen_b' => bankInvoice($klaassen, 'INV-BANK-0031', 8000),
        '0021' => bankInvoice($smit, 'INV-BANK-0021', 12000),
        '0022' => bankInvoice($smit, 'INV-BANK-0022', 5000),
    ];
}

test('certain matches are recorded as payments', function () {
    [$first, $second, $third] = certainMatchInvoices();
    $bankTransfer = PaymentMethod::whereCompanyId($this->companyId)->where('name', 'Bank Transfer')->value('id');

    $response = bankUpload('certain_matches.xml');

    $response->assertOk()->assertJson(['data' => [
        'file_name' => 'certain_matches.xml',
        'statement_id' => 'STMT-2026-301',
        'transaction_count' => 3,
        'new_count' => 3,
        'duplicate_count' => 0,
        'matched_count' => 3,
        'review_count' => 0,
    ]]);

    $payments = Payment::whereNotNull('bank_transaction_id')->orderBy('id')->get();

    expect($payments)->toHaveCount(3)
        ->and($payments->pluck('amount')->all())->toBe([12100, 25000, 9995])
        ->and($payments->pluck('invoice_id')->all())->toBe([$first->id, $second->id, $third->id])
        ->and($payments->pluck('payment_method_id')->unique()->all())->toBe([$bankTransfer])
        ->and($payments->first()->payment_date)->toStartWith('2026-10-27')
        ->and($payments->first()->unique_hash)->not->toBeNull()
        ->and($payments->first()->payment_number)->not->toBeNull();

    foreach ([$first, $second, $third] as $invoice) {
        $invoice->refresh();

        expect((int) $invoice->due_amount)->toBe(0)
            ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID);
    }

    expect(BankTransaction::count())->toBe(3)
        ->and(BankTransaction::where('bank_reference', 'ABN-0003')->exists())->toBeFalse()
        ->and(BankTransaction::pluck('status')->unique()->all())->toBe([BankTransaction::STATUS_MATCHED]);
});

test('certain matches record why they matched', function () {
    certainMatchInvoices();

    bankUpload('certain_matches.xml')->assertOk();

    expect(bankTransactionFor('ABN-0001')->match_reason)->toBe('invoice_number_and_amount')
        ->and(bankTransactionFor('ABN-0002')->match_reason)->toBe('invoice_number_and_amount')
        ->and(bankTransactionFor('ABN-0004')->match_reason)->toBe('amount_and_name');
});

test('draft invoices are not matched automatically', function () {
    $draft = bankInvoice(bankCustomer('Jansen B.V.'), 'INV-BANK-0001', 12100, Invoice::STATUS_DRAFT);

    bankUpload('certain_matches.xml')->assertOk()->assertJsonPath('data.matched_count', 0);

    expect(bankTransactionFor('ABN-0001')->status)->toBeIn(BankTransaction::OPEN_STATUSES)
        ->and(Payment::whereNotNull('bank_transaction_id')->count())->toBe(0)
        ->and((int) $draft->fresh()->due_amount)->toBe(12100);
});

test('invoices of another company are not matched', function () {
    $otherCompany = Company::factory()->create();
    $customer = bankCustomer('Jansen B.V.', null, ['company_id' => $otherCompany->id]);
    $invoice = bankInvoice($customer, 'INV-BANK-0001', 12100);

    bankUpload('certain_matches.xml')->assertOk()->assertJsonPath('data.matched_count', 0);

    expect(bankTransactionFor('ABN-0001')->status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and(bankTransactionFor('ABN-0001')->candidate_invoice_ids)->toBe([])
        ->and((int) $invoice->fresh()->due_amount)->toBe(12100)
        ->and(Payment::whereNotNull('bank_transaction_id')->count())->toBe(0);
});

test('a partial payment reduces the due amount', function () {
    $jansen = bankCustomer('Jansen B.V.');
    $invoice = bankInvoice($jansen, 'INV-BANK-0010', 12100);
    bankInvoice($jansen, 'INV-BANK-0011', 10000);

    bankUpload('partial_and_over.xml')->assertOk()->assertJsonPath('data.matched_count', 2);

    $invoice->refresh();
    $transaction = bankTransactionFor('ABN-0010');

    expect((int) $invoice->due_amount)->toBe(7100)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($transaction->status)->toBe(BankTransaction::STATUS_MATCHED)
        ->and($transaction->match_reason)->toBe('invoice_number_and_name')
        ->and($transaction->payments()->pluck('amount')->all())->toBe([5000])
        ->and($transaction->payments()->first()->invoice_id)->toBe($invoice->id);
});

test('an overpayment pays the invoice and records the rest as customer credit', function () {
    $jansen = bankCustomer('Jansen B.V.');
    bankInvoice($jansen, 'INV-BANK-0010', 12100);
    $invoice = bankInvoice($jansen, 'INV-BANK-0011', 10000);

    bankUpload('partial_and_over.xml')->assertOk();

    $invoice->refresh();
    $payments = bankTransactionFor('ABN-0011')->payments()->orderBy('id')->get();

    expect((int) $invoice->due_amount)->toBe(0)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PAID)
        ->and($payments)->toHaveCount(2)
        ->and($payments[0]->amount)->toBe(10000)
        ->and($payments[0]->invoice_id)->toBe($invoice->id)
        ->and($payments[1]->amount)->toBe(5000)
        ->and($payments[1]->invoice_id)->toBeNull()
        ->and($payments[1]->customer_id)->toBe($jansen->id)
        ->and($payments->sum('amount'))->toBe(15000);
});

test('doubtful transactions are listed for review', function () {
    $invoices = doubtfulInvoices();

    bankUpload('doubtful.xml')->assertOk()->assertJson(['data' => [
        'transaction_count' => 4,
        'new_count' => 4,
        'matched_count' => 0,
        'review_count' => 4,
    ]]);

    $onlyNumber = bankTransactionFor('ABN-0020');
    $sameAmountAndName = bankTransactionFor('ABN-0021');
    $noMatch = bankTransactionFor('ABN-0022');
    $twoNumbers = bankTransactionFor('ABN-0023');

    expect($onlyNumber->status)->toBe(BankTransaction::STATUS_AMBIGUOUS)
        ->and($onlyNumber->match_reason)->toBe('invoice_number_only')
        ->and($onlyNumber->candidate_invoice_ids)->toBe([$invoices['0020']->id])
        ->and($sameAmountAndName->status)->toBe(BankTransaction::STATUS_AMBIGUOUS)
        ->and($sameAmountAndName->match_reason)->toBe('multiple_amount_and_name')
        ->and($sameAmountAndName->candidate_invoice_ids)->toEqualCanonicalizing([$invoices['klaassen_a']->id, $invoices['klaassen_b']->id])
        ->and($noMatch->status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($noMatch->match_reason)->toBe('no_match')
        ->and($twoNumbers->status)->toBe(BankTransaction::STATUS_AMBIGUOUS)
        ->and($twoNumbers->match_reason)->toBe('multiple_invoice_numbers')
        ->and($twoNumbers->candidate_invoice_ids)->toEqualCanonicalizing([$invoices['0021']->id, $invoices['0022']->id]);

    expect(Payment::whereNotNull('bank_transaction_id')->count())->toBe(0);

    foreach ($invoices as $invoice) {
        expect((int) $invoice->fresh()->due_amount)->toBe((int) $invoice->total);
    }

    $response = getJson('api/v1/bank-transactions?status=review');

    $response->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.review_count', 4);

    $listed = collect($response->json('data'))->keyBy('id');

    expect($listed[$onlyNumber->id]['candidates'][0]['invoice_number'])->toBe('INV-BANK-0020')
        ->and($listed[$twoNumbers->id]['candidates'])->toHaveCount(2)
        ->and($listed[$noMatch->id]['candidates'])->toBe([]);
});

test('uploading the same statement twice does not process transactions again', function () {
    $invoices = certainMatchInvoices();

    bankUpload('certain_matches.xml')->assertOk();
    $paymentCount = Payment::count();

    bankUpload('certain_matches.xml')->assertOk()->assertJson(['data' => [
        'transaction_count' => 3,
        'new_count' => 0,
        'duplicate_count' => 3,
        'matched_count' => 0,
        'review_count' => 0,
    ]]);

    expect(BankTransaction::count())->toBe(3)
        ->and(BankStatementImport::count())->toBe(2)
        ->and(Payment::count())->toBe($paymentCount);

    foreach ($invoices as $invoice) {
        expect((int) $invoice->fresh()->due_amount)->toBe(0);
    }
});

test('re-uploading doubtful transactions keeps them as they were', function () {
    doubtfulInvoices();

    bankUpload('doubtful.xml')->assertOk();
    $before = BankTransaction::orderBy('id')->get(['id', 'status', 'match_reason', 'candidate_invoice_ids'])->toArray();

    // A new invoice that would now match must not cause a re-evaluation.
    bankInvoice(bankCustomer('Niemand'), 'INV-BANK-0040', 1234);

    bankUpload('doubtful.xml')->assertOk()->assertJson(['data' => [
        'new_count' => 0,
        'duplicate_count' => 4,
    ]]);

    expect(BankTransaction::orderBy('id')->get(['id', 'status', 'match_reason', 'candidate_invoice_ids'])->toArray())->toBe($before)
        ->and(Payment::whereNotNull('bank_transaction_id')->count())->toBe(0);
});

test('identical credits in one statement are both stored, but only once', function () {
    bankUpload('batch_v08.xml')->assertOk()->assertJson(['data' => [
        'statement_id' => 'STMT-2026-304',
        'transaction_count' => 4,
        'new_count' => 4,
    ]]);

    expect(BankTransaction::count())->toBe(4)
        ->and(BankTransaction::where('counterparty_name', 'Stichting Buurthuis')->count())->toBe(2)
        ->and(BankTransaction::where('counterparty_name', 'Van Dijk Installatie')->value('amount'))->toBe(20000);

    bankUpload('batch_v08.xml')->assertOk()->assertJson(['data' => [
        'new_count' => 0,
        'duplicate_count' => 4,
    ]]);

    expect(BankTransaction::count())->toBe(4);
});

test('a doubtful transaction can be linked manually, but only once', function () {
    $invoices = doubtfulInvoices();
    bankUpload('doubtful.xml')->assertOk();
    $transaction = bankTransactionFor('ABN-0020');

    postJson("api/v1/bank-transactions/{$transaction->id}/link", ['invoice_ids' => [$invoices['0020']->id]])
        ->assertOk()
        ->assertJsonPath('data.status', BankTransaction::STATUS_LINKED)
        ->assertJsonPath('data.payments.0.amount', 7500);

    $invoice = $invoices['0020']->fresh();

    expect((int) $invoice->due_amount)->toBe(22500)
        ->and($invoice->paid_status)->toBe(Invoice::STATUS_PARTIALLY_PAID)
        ->and($transaction->fresh()->processed_by)->toBe(1);

    postJson("api/v1/bank-transactions/{$transaction->id}/link", ['invoice_ids' => [$invoices['0020']->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bank_transaction');

    expect($transaction->payments()->count())->toBe(1)
        ->and((int) $invoices['0020']->fresh()->due_amount)->toBe(22500);
});

test('a transaction can be linked to several invoices with the rest as credit', function () {
    $invoices = doubtfulInvoices();
    bankUpload('doubtful.xml')->assertOk();
    $transaction = bankTransactionFor('ABN-0023');

    postJson("api/v1/bank-transactions/{$transaction->id}/link", [
        'invoice_ids' => [$invoices['0021']->id, $invoices['0022']->id],
    ])->assertOk();

    $payments = $transaction->payments()->orderBy('id')->get();

    expect($invoices['0021']->fresh()->paid_status)->toBe(Invoice::STATUS_PAID)
        ->and($invoices['0022']->fresh()->paid_status)->toBe(Invoice::STATUS_PAID)
        ->and($payments->pluck('amount')->all())->toBe([12000, 5000, 3000])
        ->and($payments[2]->invoice_id)->toBeNull()
        ->and($payments[2]->customer_id)->toBe($invoices['0022']->customer_id)
        ->and($transaction->fresh()->status)->toBe(BankTransaction::STATUS_LINKED);
});

test('linking validates the chosen invoices', function () {
    doubtfulInvoices();
    bankUpload('doubtful.xml')->assertOk();
    $transaction = bankTransactionFor('ABN-0022');

    $otherCompany = Company::factory()->create();
    $foreign = bankInvoice(bankCustomer('Ander', null, ['company_id' => $otherCompany->id]), 'INV-OTHER-1', 1234);
    $paid = bankInvoice(bankCustomer('Betaald'), 'INV-PAID-1', 1234, Invoice::STATUS_COMPLETED, [
        'paid_status' => Invoice::STATUS_PAID,
        'due_amount' => 0,
    ]);
    $usd = Currency::where('code', 'USD')->first();
    $dollars = bankInvoice(bankCustomer('Dollar Inc', null, ['currency_id' => $usd->id]), 'INV-USD-1', 1234, Invoice::STATUS_SENT, [
        'currency_id' => $usd->id,
    ]);

    postJson("api/v1/bank-transactions/{$transaction->id}/link", ['invoice_ids' => [$foreign->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_ids.0');

    foreach ([$paid, $dollars] as $invoice) {
        postJson("api/v1/bank-transactions/{$transaction->id}/link", ['invoice_ids' => [$invoice->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invoice_ids');
    }

    postJson("api/v1/bank-transactions/{$transaction->id}/link", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('invoice_ids');

    expect($transaction->fresh()->status)->toBe(BankTransaction::STATUS_UNMATCHED)
        ->and($transaction->payments()->count())->toBe(0);
});

test('a transaction can be ignored and linked later, but a linked one cannot be ignored', function () {
    bankUpload('doubtful.xml')->assertOk();
    $transaction = bankTransactionFor('ABN-0022');

    postJson("api/v1/bank-transactions/{$transaction->id}/ignore")
        ->assertOk()
        ->assertJsonPath('data.status', BankTransaction::STATUS_IGNORED);

    expect($transaction->payments()->count())->toBe(0);

    getJson('api/v1/bank-transactions?status=IGNORED')->assertOk()->assertJsonCount(1, 'data');

    $invoice = bankInvoice(bankCustomer('Niemand'), 'INV-BANK-0040', 1234);

    postJson("api/v1/bank-transactions/{$transaction->id}/link", ['invoice_ids' => [$invoice->id]])
        ->assertOk()
        ->assertJsonPath('data.status', BankTransaction::STATUS_LINKED);

    postJson("api/v1/bank-transactions/{$transaction->id}/ignore")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bank_transaction');

    expect($transaction->fresh()->status)->toBe(BankTransaction::STATUS_LINKED)
        ->and($invoice->fresh()->paid_status)->toBe(Invoice::STATUS_PAID);
});

test('transactions of another company cannot be linked or ignored', function () {
    $otherCompany = Company::factory()->create();
    $user = User::find(1);

    if ($user->hasCompany($otherCompany->id)) {
        $user->companies()->detach($otherCompany->id);
    }

    $transaction = BankTransaction::factory()->create(['company_id' => $otherCompany->id]);

    postJson("api/v1/bank-transactions/{$transaction->id}/ignore")->assertStatus(422);

    expect($transaction->fresh()->status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

test('invalid statements are rejected', function (string $fixture, string $error) {
    bankUpload($fixture)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'bank_transactions.errors.'.$error);

    expect(BankStatementImport::count())->toBe(0)
        ->and(BankTransaction::count())->toBe(0);
})->with([
    ['invalid_not_camt.xml', 'not_camt053'],
    ['invalid_doctype.xml', 'doctype_not_allowed'],
]);

test('a file that is not xml is rejected', function () {
    $file = UploadedFile::fake()->createWithContent('statement.xml', 'this is not xml');

    postJson('api/v1/bank-statements/import', ['file' => $file])
        ->assertUnprocessable()
        ->assertJsonPath('error', 'bank_transactions.errors.invalid_xml');

    postJson('api/v1/bank-statements/import', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    expect(BankTransaction::count())->toBe(0);
});

test('import and link use form requests', function () {
    $this->assertActionUsesFormRequest(ImportBankStatementController::class, '__invoke', ImportBankStatementRequest::class);
    $this->assertActionUsesFormRequest(LinkBankTransactionController::class, '__invoke', LinkBankTransactionRequest::class);
});

test('users without the create payment ability cannot import statements', function () {
    $user = User::factory()->create();
    $user->companies()->attach($this->companyId);

    Sanctum::actingAs($user, ['*']);

    bankUpload('certain_matches.xml')->assertForbidden();

    expect(BankTransaction::count())->toBe(0);
});

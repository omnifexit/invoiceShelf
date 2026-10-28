<?php

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\User;
use App\Services\GeminiInvoiceParser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

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

    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();
});

function geminiDraftResponse(array $payload, string $finishReason = 'STOP'): array
{
    return ['candidates' => [[
        'content' => ['role' => 'model', 'parts' => [['text' => json_encode($payload)]]],
        'finishReason' => $finishReason,
    ]]];
}

function fakeGeminiDraft(array $payload): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(geminiDraftResponse(array_merge([
            'customer_name' => null,
            'invoice_date' => null,
            'due_date' => null,
            'items' => [],
        ], $payload))),
    ]);
}

function draftCustomer(string $name, array $attributes = []): Customer
{
    return Customer::factory()->create(array_merge([
        'name' => $name,
        'company_name' => $name.' BV',
        'contact_name' => 'Contact '.$name,
    ], $attributes));
}

function draftItem(string $name, array $attributes = []): Item
{
    return Item::factory()->create(array_merge([
        'name' => $name,
        'description' => 'Omschrijving '.$name,
    ], $attributes));
}

function draftWarningCodes($response): array
{
    return collect($response->json('data.warnings'))->pluck('code')->all();
}

function fullDraftPayload(): array
{
    return [
        'customer_name' => 'Jansen Bouwadvies Zwolle',
        'invoice_date' => '2026-10-28',
        'due_date' => '2026-11-11',
        'items' => [
            ['name' => 'Consultancy uur', 'description' => null, 'quantity' => 3, 'price' => 85],
        ],
    ];
}

test('parses text into a matched invoice draft', function () {
    $customer = draftCustomer('Jansen Bouwadvies Zwolle');
    $item = draftItem('Consultancy uur', ['price' => 9000]);

    fakeGeminiDraft(fullDraftPayload());

    $text = 'Factuur voor Jansen Bouwadvies Zwolle, drie uur consultancy a 85 euro, vervaldatum over twee weken';

    $response = postJson('api/v1/invoices/parse-text', ['text' => $text])->assertOk();

    expect($response->json('data.text'))->toBe($text)
        ->and($response->json('data.customer.status'))->toBe('matched')
        ->and($response->json('data.customer.id'))->toBe($customer->id)
        ->and($response->json('data.items.0.item_id'))->toBe($item->id)
        ->and($response->json('data.items.0.status'))->toBe('matched')
        ->and($response->json('data.items.0.price'))->toBe(8500)
        ->and($response->json('data.items.0.quantity'))->toEqual(3)
        ->and($response->json('data.items.0.description'))->toBe('Omschrijving Consultancy uur')
        ->and($response->json('data.invoice_date'))->toBe('2026-10-28')
        ->and($response->json('data.due_date'))->toBe('2026-11-11')
        ->and($response->json('data.warnings'))->toBe([]);

    Http::assertSent(function (Request $request) use ($text) {
        return str_contains($request->url(), ':generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key')
            && $request['generationConfig']['responseMimeType'] === 'application/json'
            && $request['generationConfig']['responseSchema'] === GeminiInvoiceParser::responseSchema()
            && $request['contents'][0]['parts'][0]['text'] === $text;
    });
});

test('does not save or send anything', function () {
    Mail::fake();
    draftCustomer('Jansen Bouwadvies Zwolle');
    draftItem('Consultancy uur');

    fakeGeminiDraft(fullDraftPayload());

    $invoiceCount = Invoice::count();

    postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])->assertOk();

    expect(Invoice::count())->toBe($invoiceCount);
    Mail::assertNothingSent();
});

test('matches customer case-insensitively and by partial name', function () {
    $customer = draftCustomer('Jansen Bouwadvies Zwolle');

    fakeGeminiDraft(['customer_name' => 'jansen bouwadvies']);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor jansen bouwadvies'])->assertOk();

    expect($response->json('data.customer.status'))->toBe('matched')
        ->and($response->json('data.customer.id'))->toBe($customer->id)
        ->and($response->json('data.customer.name'))->toBe('Jansen Bouwadvies Zwolle');
});

test('prefers an exact customer match over partial matches', function () {
    $customer = draftCustomer('Jansen Bouwadvies');
    draftCustomer('Jansen Bouwadvies Deventer');

    fakeGeminiDraft(['customer_name' => 'Jansen Bouwadvies']);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen Bouwadvies'])->assertOk();

    expect($response->json('data.customer.status'))->toBe('matched')
        ->and($response->json('data.customer.id'))->toBe($customer->id);
});

test('reports an ambiguous customer instead of guessing', function () {
    draftCustomer('Jansen Bouwadvies Zwolle');
    draftCustomer('Jansen Bouwadvies Deventer');

    fakeGeminiDraft(['customer_name' => 'Jansen Bouwadvies']);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen Bouwadvies'])->assertOk();

    expect($response->json('data.customer.status'))->toBe('ambiguous')
        ->and($response->json('data.customer.id'))->toBeNull()
        ->and($response->json('data.customer.name'))->toBe('Jansen Bouwadvies')
        ->and($response->json('data.customer.candidates'))->toHaveCount(2)
        ->and(draftWarningCodes($response))->toContain('customer_ambiguous');
});

test('reports an unknown customer and item as not found', function () {
    fakeGeminiDraft([
        'customer_name' => 'Onbekende Klant XYZ',
        'items' => [['name' => 'Tuinonderhoud XYZ', 'description' => null, 'quantity' => 2, 'price' => 50]],
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Onbekende Klant XYZ'])->assertOk();

    expect($response->json('data.customer.status'))->toBe('not_found')
        ->and($response->json('data.customer.id'))->toBeNull()
        ->and($response->json('data.items.0.item_id'))->toBeNull()
        ->and($response->json('data.items.0.status'))->toBe('not_found')
        ->and($response->json('data.items.0.name'))->toBe('Tuinonderhoud XYZ')
        ->and($response->json('data.items.0.price'))->toBe(5000)
        ->and(draftWarningCodes($response))->toContain('customer_not_found', 'item_not_found');
});

test('reports an ambiguous item', function () {
    draftItem('Consultancy uur senior');
    draftItem('Consultancy uur junior');

    fakeGeminiDraft([
        'items' => [['name' => 'Consultancy uur', 'description' => null, 'quantity' => 1, 'price' => 100]],
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Een uur consultancy'])->assertOk();

    expect($response->json('data.items.0.status'))->toBe('ambiguous')
        ->and($response->json('data.items.0.item_id'))->toBeNull()
        ->and($response->json('data.items.0.candidates'))->toHaveCount(2)
        ->and(draftWarningCodes($response))->toContain('item_ambiguous');
});

test('does not match customers or items of another company', function () {
    $otherCompany = Company::factory()->create();
    draftCustomer('Jansen Bouwadvies Zwolle', ['company_id' => $otherCompany->id]);
    draftItem('Consultancy uur', ['company_id' => $otherCompany->id]);

    fakeGeminiDraft(fullDraftPayload());

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])->assertOk();

    expect($response->json('data.customer.status'))->toBe('not_found')
        ->and($response->json('data.items.0.status'))->toBe('not_found')
        ->and($response->json('data.items.0.item_id'))->toBeNull();
});

test('uses the item price when no price was spoken', function () {
    draftItem('Consultancy uur', ['price' => 12345]);

    fakeGeminiDraft([
        'items' => [['name' => 'Consultancy uur', 'description' => null, 'quantity' => 2, 'price' => null]],
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Twee uur consultancy'])->assertOk();

    expect($response->json('data.items.0.price'))->toBe(12345)
        ->and(draftWarningCodes($response))->not->toContain('price_missing');
});

test('handles an unclear answer with missing fields', function () {
    fakeGeminiDraft([
        'customer_name' => null,
        'invoice_date' => null,
        'due_date' => 'over twee weken',
        'items' => [],
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'eh, iets met een factuur'])->assertOk();

    expect($response->json('data.customer.status'))->toBe('not_mentioned')
        ->and($response->json('data.invoice_date'))->toBeNull()
        ->and($response->json('data.due_date'))->toBeNull()
        ->and($response->json('data.items'))->toBe([])
        ->and(draftWarningCodes($response))->toContain('customer_not_mentioned', 'due_date_invalid', 'no_items');
});

test('defaults missing quantity to 1 and flags missing price', function () {
    fakeGeminiDraft([
        'items' => [['name' => 'Iets onbekends XYZ', 'description' => null, 'quantity' => null, 'price' => null]],
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Iets onbekends'])->assertOk();

    expect($response->json('data.items.0.quantity'))->toEqual(1)
        ->and($response->json('data.items.0.price'))->toBe(0)
        ->and(draftWarningCodes($response))->toContain('quantity_missing', 'price_missing');

    $warning = collect($response->json('data.warnings'))->firstWhere('code', 'price_missing');
    expect($warning['index'])->toBe(1)
        ->and($warning['name'])->toBe('Iets onbekends XYZ');
});

test('drops a due date before the invoice date', function () {
    fakeGeminiDraft([
        'invoice_date' => '2026-10-28',
        'due_date' => '2026-10-01',
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur met rare datum'])->assertOk();

    expect($response->json('data.invoice_date'))->toBe('2026-10-28')
        ->and($response->json('data.due_date'))->toBeNull()
        ->and(draftWarningCodes($response))->toContain('due_date_before_invoice_date');
});

test('returns a clear error when no api key is configured', function () {
    config(['services.gemini.api_key' => null]);
    Http::fake();

    postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])
        ->assertStatus(503)
        ->assertJsonPath('error', 'invoices.ai.errors.gemini_not_configured')
        ->assertJson(fn ($json) => $json->where('message', fn ($message) => str_contains($message, 'GEMINI_API_KEY'))->etc());

    Http::assertNothingSent();
});

test('returns a clear error when gemini responds with an error', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 500, 'message' => 'Internal error', 'status' => 'INTERNAL'],
        ], 500),
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'invoices.ai.errors.gemini_request_failed');

    expect($response->json('message'))->toContain('Internal error')
        ->and($response->getContent())->not->toContain('test-key');
});

test('returns a clear error for an invalid api key', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT'],
        ], 400),
    ]);

    $response = postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'invoices.ai.errors.gemini_request_failed');

    expect($response->json('message'))->toContain('API key not valid');
});

test('returns a clear error when gemini cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'invoices.ai.errors.gemini_request_failed');
});

test('returns a clear error for a non-json answer', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [['text' => 'Sorry, ik begrijp het niet']]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);

    postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'invoices.ai.errors.gemini_invalid_response');
});

test('returns a clear error for an empty or blocked answer', function (array $body) {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response($body),
    ]);

    postJson('api/v1/invoices/parse-text', ['text' => 'Factuur voor Jansen'])
        ->assertStatus(502)
        ->assertJsonPath('error', 'invoices.ai.errors.gemini_invalid_response');
})->with([
    'no candidates' => [['candidates' => []]],
    'blocked prompt' => [['promptFeedback' => ['blockReason' => 'SAFETY']]],
    'truncated answer' => [geminiDraftResponse(['customer_name' => 'Jansen'], 'MAX_TOKENS')],
]);

test('validates the text', function () {
    Http::fake();

    postJson('api/v1/invoices/parse-text', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');

    postJson('api/v1/invoices/parse-text', ['text' => str_repeat('a', 2001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');

    Http::assertNothingSent();
});

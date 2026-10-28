<?php

use App\Exceptions\GeminiException;
use App\Services\GeminiInvoiceParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.gemini.api_key' => 'test-key']);
});

function fakeGeminiParserResponse(array $payload): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => json_encode($payload)]]],
            'finishReason' => 'STOP',
        ]]]),
    ]);
}

test('response schema describes the invoice fields', function () {
    $schema = GeminiInvoiceParser::responseSchema();

    expect($schema['properties'])->toHaveKeys(['customer_name', 'invoice_date', 'due_date', 'items'])
        ->and($schema['properties']['items']['items']['properties'])->toHaveKeys(['name', 'quantity', 'price'])
        ->and($schema['properties']['items']['items']['required'])->toContain('name');
});

test('parse normalizes the answer', function () {
    fakeGeminiParserResponse([
        'customer_name' => '  ',
        'invoice_date' => '2026-10-28',
        'due_date' => null,
        'items' => [
            ['name' => '', 'quantity' => 1, 'price' => 10],
            ['name' => 'Consultancy', 'quantity' => '3', 'price' => 'abc'],
            'not a line',
        ],
    ]);

    $result = (new GeminiInvoiceParser)->parse('tekst', '2026-10-28');

    expect($result['customer_name'])->toBeNull()
        ->and($result['invoice_date'])->toBe('2026-10-28')
        ->and($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['name'])->toBe('Consultancy')
        ->and($result['items'][0]['quantity'])->toBe(3)
        ->and($result['items'][0]['price'])->toBeNull();
});

test('parse sends today in the system instruction', function () {
    fakeGeminiParserResponse(['customer_name' => null, 'invoice_date' => null, 'due_date' => null, 'items' => []]);

    (new GeminiInvoiceParser)->parse('vervaldatum over twee weken', '2026-10-28');

    Http::assertSent(fn (Request $request) => str_contains($request['systemInstruction']['parts'][0]['text'], '2026-10-28'));
});

test('parse throws when no api key is configured', function () {
    config(['services.gemini.api_key' => null]);

    try {
        (new GeminiInvoiceParser)->parse('tekst', '2026-10-28');
        $this->fail('Expected a GeminiException.');
    } catch (GeminiException $e) {
        expect($e->errorCode)->toBe(GeminiException::NOT_CONFIGURED)
            ->and($e->status)->toBe(503);
    }
});

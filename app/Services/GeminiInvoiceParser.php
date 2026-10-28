<?php

namespace App\Services;

use App\Exceptions\GeminiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Extracts invoice data from free text with the Google Gemini API.
 */
class GeminiInvoiceParser
{
    /**
     * @return array{customer_name: ?string, invoice_date: ?string, due_date: ?string, items: array<int, array{name: string, description: ?string, quantity: int|float|null, price: int|float|null}>}
     *
     * @throws GeminiException
     */
    public function parse(string $text, string $today): array
    {
        $apiKey = config('services.gemini.api_key');

        if (blank($apiKey)) {
            throw GeminiException::notConfigured();
        }

        $url = rtrim((string) config('services.gemini.base_url'), '/')
            .'/models/'.config('services.gemini.model').':generateContent';

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $this->instructions($today)]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $text]]]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => self::responseSchema(),
            ],
        ];

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->acceptJson()
                ->timeout((int) config('services.gemini.timeout'))
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw GeminiException::requestFailed('the Gemini API could not be reached.', $e);
        }

        if ($response->failed()) {
            Log::warning('Gemini request failed', [
                'status' => $response->status(),
                'body' => $response->json('error'),
            ]);

            $message = $response->json('error.message');
            $detail = is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status();

            throw GeminiException::requestFailed($detail);
        }

        $blockReason = $response->json('promptFeedback.blockReason');

        if ($blockReason) {
            throw GeminiException::invalidResponse('the request was blocked ('.$blockReason.').');
        }

        $content = null;

        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $content = $part['text'];

                break;
            }
        }

        if ($content === null) {
            throw GeminiException::invalidResponse('no content was returned.');
        }

        $finishReason = $response->json('candidates.0.finishReason');

        if (! in_array($finishReason, ['STOP', null], true)) {
            throw GeminiException::invalidResponse('generation stopped ('.$finishReason.').');
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw GeminiException::invalidResponse('the content is not valid JSON.');
        }

        return $this->normalize($data);
    }

    /**
     * The structured output schema sent to Gemini.
     */
    public static function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'customer_name' => [
                    'type' => 'STRING',
                    'nullable' => true,
                    'description' => 'Name of the customer the invoice is for, as mentioned in the text.',
                ],
                'invoice_date' => [
                    'type' => 'STRING',
                    'nullable' => true,
                    'description' => 'Invoice date as YYYY-MM-DD.',
                ],
                'due_date' => [
                    'type' => 'STRING',
                    'nullable' => true,
                    'description' => 'Due date as YYYY-MM-DD.',
                ],
                'items' => [
                    'type' => 'ARRAY',
                    'description' => 'The invoice lines.',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'name' => [
                                'type' => 'STRING',
                                'description' => 'Name of the product or service.',
                            ],
                            'description' => [
                                'type' => 'STRING',
                                'nullable' => true,
                                'description' => 'Optional extra description of the line.',
                            ],
                            'quantity' => [
                                'type' => 'NUMBER',
                                'nullable' => true,
                                'description' => 'Quantity, or null when not mentioned.',
                            ],
                            'price' => [
                                'type' => 'NUMBER',
                                'nullable' => true,
                                'description' => 'Unit price in the main currency unit, or null when not mentioned.',
                            ],
                        ],
                        'required' => ['name', 'quantity', 'price'],
                        'propertyOrdering' => ['name', 'description', 'quantity', 'price'],
                    ],
                ],
            ],
            'required' => ['customer_name', 'invoice_date', 'due_date', 'items'],
            'propertyOrdering' => ['customer_name', 'invoice_date', 'due_date', 'items'],
        ];
    }

    private function instructions(string $today): string
    {
        return implode("\n", [
            'You extract invoice data from a short spoken or typed description. The description may be in Dutch or any other language.',
            'Extract the customer name, the invoice lines (item, quantity, unit price), the invoice date and the due date.',
            "Today is {$today}. Resolve relative dates (e.g. 'in two weeks') against today and return dates as YYYY-MM-DD.",
            'Return null for anything that is not mentioned. Never invent customers, items, prices or dates.',
            "price is the unit price as a number in the currency's main unit (e.g. 85 or 85.50), without currency symbols.",
            'quantity is a number; if no quantity is mentioned use null.',
            'Return the customer and item names as spoken; do not translate them.',
        ]);
    }

    /**
     * @return array{customer_name: ?string, invoice_date: ?string, due_date: ?string, items: array<int, array{name: string, description: ?string, quantity: int|float|null, price: int|float|null}>}
     */
    private function normalize(array $data): array
    {
        $items = [];

        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->stringOrNull($item['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $items[] = [
                'name' => $name,
                'description' => $this->stringOrNull($item['description'] ?? null),
                'quantity' => $this->numberOrNull($item['quantity'] ?? null),
                'price' => $this->numberOrNull($item['price'] ?? null),
            ];
        }

        return [
            'customer_name' => $this->stringOrNull($data['customer_name'] ?? null),
            'invoice_date' => $this->stringOrNull($data['invoice_date'] ?? null),
            'due_date' => $this->stringOrNull($data['due_date'] ?? null),
            'items' => $items,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function numberOrNull(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }

        return null;
    }
}

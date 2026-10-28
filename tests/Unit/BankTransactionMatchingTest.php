<?php

use App\Models\BankTransaction;

test('finds the invoice number in a description regardless of notation', function (string $description, string $number, bool $expected) {
    expect(BankTransaction::descriptionContainsInvoiceNumber($description, $number))->toBe($expected);
})->with([
    ['inv 000012', 'INV-000012', true],
    ['INV000012', 'INV-000012', true],
    ['Factuur INV-000012 bedankt', 'INV-000012', true],
    ['factuur inv/000012', 'INV-000012', true],
    ['INV-000012', 'INV-00001', false],
    ['XINV-000012', 'INV-000012', false],
    ['Betaling', 'INV-000012', false],
    ['nummer 12', '12', false],
]);

test('ignores empty descriptions', function () {
    expect(BankTransaction::descriptionContainsInvoiceNumber(null, 'INV-000012'))->toBeFalse()
        ->and(BankTransaction::descriptionContainsInvoiceNumber('', 'INV-000012'))->toBeFalse();
});

test('normalizes names without legal forms', function () {
    expect(BankTransaction::normalizeName('Jansen B.V.'))->toBe('jansen')
        ->and(BankTransaction::normalizeName('jansen bv'))->toBe('jansen')
        ->and(BankTransaction::normalizeName('De Vries & Zn. GmbH'))->toBe('devrieszn');
});

test('compares counterparty names', function (?string $first, ?string $second, bool $expected) {
    expect(BankTransaction::namesMatch($first, $second))->toBe($expected);
})->with([
    ['Jansen B.V.', 'jansen bv', true],
    ['Bakkerij De Vries', 'De Vries', true],
    ['Klaassen', 'Klaassen', true],
    ['Smit', 'Jansen', false],
    ['Bo', 'Bob Jansen', false],
    ['', 'Jansen', false],
    [null, null, false],
    ['B.V.', 'BV', false],
]);

<?php

use App\Exceptions\BankStatementImportException;
use App\Services\Camt053Parser;

function camtFixture(string $name): string
{
    return file_get_contents(base_path('tests/Fixtures/Camt053/'.$name));
}

test('toCents converts CAMT amounts to integer cents', function (string $amount, int $expected) {
    expect(Camt053Parser::toCents($amount))->toBe($expected);
})->with([
    ['121.00', 12100],
    ['0.5', 50],
    ['1234', 123400],
    ['99.95', 9995],
    [' 12.34 ', 1234],
]);

test('toCents rejects invalid amounts', function (string $amount) {
    Camt053Parser::toCents($amount);
})->with(['1.999', '-1', 'abc', '', '1,50'])->throws(BankStatementImportException::class);

test('parses the credit entries of a camt.053.001.02 statement', function () {
    $result = (new Camt053Parser)->parse(camtFixture('certain_matches.xml'));

    expect($result['statement_id'])->toBe('STMT-2026-301')
        ->and($result['account_iban'])->toBe('NL91ABNA0417164300')
        ->and($result['transactions'])->toHaveCount(3);

    $first = $result['transactions'][0];

    expect($first['amount'])->toBe(12100)
        ->and($first['currency_code'])->toBe('EUR')
        ->and($first['counterparty_name'])->toBe('Jansen B.V.')
        ->and($first['counterparty_iban'])->toBe('NL20INGB0001234567')
        ->and($first['description'])->toBe('Betaling factuur INV-BANK-0001')
        ->and($first['booking_date'])->toBe('2026-10-27')
        ->and($first['value_date'])->toBe('2026-10-27')
        ->and($first['end_to_end_id'])->toBe('E2E-0001')
        ->and($first['bank_reference'])->toBe('ABN-0001')
        ->and($first['fingerprint'])->toHaveLength(64);

    expect(array_column($result['transactions'], 'amount'))->toBe([12100, 25000, 9995])
        ->and(array_column($result['transactions'], 'counterparty_name'))->not->toContain('Energieleverancier NV');
});

test('parses camt.053.001.08 statements with batch entries', function () {
    $result = (new Camt053Parser)->parse(camtFixture('batch_v08.xml'));
    $transactions = $result['transactions'];

    expect($transactions)->toHaveCount(4)
        ->and(array_column($transactions, 'amount'))->toBe([20000, 10050, 2500, 2500])
        ->and($transactions[0]['counterparty_name'])->toBe('Van Dijk Installatie')
        ->and($transactions[0]['booking_date'])->toBe('2026-10-26')
        ->and($transactions[0]['bank_reference'])->toBe('BATCH-0001-1')
        ->and($transactions[1]['description'])->toBe('RF18539007547034')
        ->and($transactions[2]['end_to_end_id'])->toBeNull();

    $fingerprints = array_column($transactions, 'fingerprint');

    expect(array_unique($fingerprints))->toHaveCount(4);
});

test('fingerprints are deterministic', function () {
    $parser = new Camt053Parser;

    $first = array_column($parser->parse(camtFixture('batch_v08.xml'))['transactions'], 'fingerprint');
    $second = array_column($parser->parse(camtFixture('batch_v08.xml'))['transactions'], 'fingerprint');

    expect($first)->toBe($second);
});

test('rejects files that are not a camt.053 statement', function (string $xml, string $error) {
    try {
        (new Camt053Parser)->parse($xml);
        $this->fail('Expected a BankStatementImportException.');
    } catch (BankStatementImportException $exception) {
        expect($exception->error)->toBe($error);
    }
})->with([
    'not camt.053' => [fn () => camtFixture('invalid_not_camt.xml'), BankStatementImportException::NOT_CAMT053],
    'doctype' => [fn () => camtFixture('invalid_doctype.xml'), BankStatementImportException::DOCTYPE_NOT_ALLOWED],
    'not xml' => ['this is not xml', BankStatementImportException::INVALID_XML],
    'empty' => ['', BankStatementImportException::INVALID_XML],
    'no statement' => ['<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt/></Document>', BankStatementImportException::NO_STATEMENT],
]);

<?php

namespace App\Services;

use App\Exceptions\BankStatementImportException;
use SimpleXMLElement;

/**
 * Reads the booked credit entries from an ISO 20022 CAMT.053 bank statement.
 *
 * All versions of camt.053.001.xx are accepted. Amounts are converted to
 * integer cents with string operations, never through floats.
 */
class Camt053Parser
{
    public const NAMESPACE_PREFIX = 'urn:iso:std:iso:20022:tech:xsd:camt.053.';

    private const MAX_DESCRIPTION_LENGTH = 1000;

    private string $namespace = '';

    /**
     * @return array{
     *     statement_id: ?string,
     *     account_iban: ?string,
     *     transactions: list<array{
     *         fingerprint: string,
     *         account_iban: ?string,
     *         booking_date: string,
     *         value_date: ?string,
     *         amount: int,
     *         currency_code: string,
     *         counterparty_name: ?string,
     *         counterparty_iban: ?string,
     *         description: ?string,
     *         end_to_end_id: ?string,
     *         bank_reference: ?string
     *     }>
     * }
     *
     * @throws BankStatementImportException
     */
    public function parse(string $xml): array
    {
        if (trim($xml) === '') {
            throw new BankStatementImportException(BankStatementImportException::INVALID_XML);
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new BankStatementImportException(BankStatementImportException::DOCTYPE_NOT_ALLOWED);
        }

        $document = $this->load($xml);

        $namespaces = $document->getNamespaces();
        $this->namespace = (string) ($namespaces[''] ?? '');

        if ($document->getName() !== 'Document' || ! str_starts_with($this->namespace, self::NAMESPACE_PREFIX)) {
            throw new BankStatementImportException(BankStatementImportException::NOT_CAMT053);
        }

        $statements = $this->nodes($document, 'c:BkToCstmrStmt/c:Stmt');

        if ($statements === []) {
            throw new BankStatementImportException(BankStatementImportException::NO_STATEMENT);
        }

        $statementId = null;
        $accountIban = null;
        $transactions = [];
        $occurrences = [];

        foreach ($statements as $statement) {
            $iban = $this->value($statement, 'c:Acct/c:Id/c:IBAN');
            $statementId ??= $this->value($statement, 'c:Id');
            $accountIban ??= $iban;

            foreach ($this->nodes($statement, 'c:Ntry') as $entry) {
                foreach ($this->parseEntry($entry, $iban) as $transaction) {
                    $key = implode('|', [
                        $transaction['account_iban'],
                        $transaction['booking_date'],
                        $transaction['amount'],
                        $transaction['currency_code'],
                        $transaction['bank_reference'],
                        $transaction['end_to_end_id'],
                        $transaction['counterparty_iban'],
                        $transaction['counterparty_name'],
                        $transaction['description'],
                    ]);

                    $occurrence = $occurrences[$key] ?? 0;
                    $occurrences[$key] = $occurrence + 1;

                    $transactions[] = ['fingerprint' => hash('sha256', $key.'|'.$occurrence)] + $transaction;
                }
            }
        }

        return [
            'statement_id' => $statementId,
            'account_iban' => $accountIban,
            'transactions' => $transactions,
        ];
    }

    /**
     * Convert a CAMT amount such as "1234.5" to integer cents.
     *
     * @throws BankStatementImportException
     */
    public static function toCents(string $amount): int
    {
        $amount = trim($amount);

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new BankStatementImportException(BankStatementImportException::INVALID_AMOUNT);
        }

        $parts = explode('.', $amount);
        $fraction = str_pad($parts[1] ?? '0', 2, '0');

        return (int) $parts[0] * 100 + (int) $fraction;
    }

    private function load(string $xml): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            throw new BankStatementImportException(BankStatementImportException::INVALID_XML);
        }

        return $document;
    }

    /**
     * @return list<array{
     *     account_iban: ?string,
     *     booking_date: string,
     *     value_date: ?string,
     *     amount: int,
     *     currency_code: string,
     *     counterparty_name: ?string,
     *     counterparty_iban: ?string,
     *     description: ?string,
     *     end_to_end_id: ?string,
     *     bank_reference: ?string
     * }>
     */
    private function parseEntry(SimpleXMLElement $entry, ?string $accountIban): array
    {
        if ($this->value($entry, 'c:CdtDbtInd') !== 'CRDT') {
            return [];
        }

        if (strtolower((string) $this->value($entry, 'c:RvslInd')) === 'true') {
            return [];
        }

        if ($this->nodes($entry, 'c:Sts') !== []) {
            $status = $this->value($entry, 'c:Sts/c:Cd') ?? $this->value($entry, 'c:Sts');

            if ($status !== 'BOOK') {
                return [];
            }
        }

        $valueDate = $this->date($entry, 'c:ValDt');
        $bookingDate = $this->date($entry, 'c:BookgDt') ?? $valueDate;

        if ($bookingDate === null) {
            return [];
        }

        $entryReference = $this->value($entry, 'c:AcctSvcrRef');
        $entryInfo = $this->value($entry, 'c:AddtlNtryInf');
        $details = $this->nodes($entry, 'c:NtryDtls/c:TxDtls');
        $entryAmount = $this->nodes($entry, 'c:Amt')[0] ?? null;

        if ($details === []) {
            $details = [null];
        }

        $transactions = [];

        foreach ($details as $detail) {
            $amountNode = $detail ? ($this->nodes($detail, 'c:Amt')[0] ?? null) : null;

            if ($amountNode === null && count($details) === 1) {
                $amountNode = $entryAmount;
            }

            if ($amountNode === null) {
                continue;
            }

            $amount = self::toCents((string) $amountNode);

            if ($amount === 0) {
                continue;
            }

            $endToEndId = $detail ? $this->value($detail, 'c:Refs/c:EndToEndId') : null;

            if ($endToEndId !== null && strtoupper($endToEndId) === 'NOTPROVIDED') {
                $endToEndId = null;
            }

            $transactions[] = [
                'account_iban' => $accountIban,
                'booking_date' => $bookingDate,
                'value_date' => $valueDate,
                'amount' => $amount,
                'currency_code' => strtoupper((string) ($amountNode['Ccy'] ?? '')),
                'counterparty_name' => $detail ? $this->counterpartyName($detail) : null,
                'counterparty_iban' => $detail ? $this->value($detail, 'c:RltdPties/c:DbtrAcct/c:Id/c:IBAN') : null,
                'description' => $this->description($detail, $entryInfo),
                'end_to_end_id' => $endToEndId,
                'bank_reference' => ($detail ? $this->value($detail, 'c:Refs/c:AcctSvcrRef') : null) ?? $entryReference,
            ];
        }

        return $transactions;
    }

    private function counterpartyName(SimpleXMLElement $detail): ?string
    {
        return $this->value($detail, 'c:RltdPties/c:Dbtr/c:Nm')
            ?? $this->value($detail, 'c:RltdPties/c:Dbtr/c:Pty/c:Nm')
            ?? $this->value($detail, 'c:RltdPties/c:UltmtDbtr/c:Nm')
            ?? $this->value($detail, 'c:RltdPties/c:UltmtDbtr/c:Pty/c:Nm');
    }

    private function description(?SimpleXMLElement $detail, ?string $entryInfo): ?string
    {
        $parts = [];

        if ($detail) {
            foreach ($this->nodes($detail, 'c:RmtInf/c:Ustrd') as $line) {
                $parts[] = trim((string) $line);
            }

            foreach ($this->nodes($detail, 'c:RmtInf/c:Strd/c:CdtrRefInf/c:Ref') as $reference) {
                $parts[] = trim((string) $reference);
            }
        }

        $parts = array_filter($parts, fn (string $part) => $part !== '');

        if ($parts === [] && $entryInfo !== null) {
            $parts = [$entryInfo];
        }

        $description = trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));

        if ($description === '') {
            return null;
        }

        return mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH);
    }

    private function date(SimpleXMLElement $element, string $path): ?string
    {
        $date = $this->value($element, $path.'/c:Dt') ?? $this->value($element, $path.'/c:DtTm');

        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return null;
        }

        return substr($date, 0, 10);
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private function nodes(SimpleXMLElement $element, string $path): array
    {
        $element->registerXPathNamespace('c', $this->namespace);

        $result = $element->xpath($path);

        return $result ? array_values($result) : [];
    }

    private function value(SimpleXMLElement $element, string $path): ?string
    {
        $node = $this->nodes($element, $path)[0] ?? null;

        if ($node === null) {
            return null;
        }

        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }
}

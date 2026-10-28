<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class BankStatementImportException extends Exception
{
    public const INVALID_XML = 'invalid_xml';

    public const NOT_CAMT053 = 'not_camt053';

    public const DOCTYPE_NOT_ALLOWED = 'doctype_not_allowed';

    public const INVALID_AMOUNT = 'invalid_amount';

    public const NO_STATEMENT = 'no_statement';

    public function __construct(public readonly string $error)
    {
        parent::__construct('The bank statement cannot be imported: '.$error.'.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'bank_transactions.errors.'.$this->error,
            'message' => $this->getMessage(),
        ], 422);
    }
}

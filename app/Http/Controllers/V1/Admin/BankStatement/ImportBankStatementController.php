<?php

namespace App\Http\Controllers\V1\Admin\BankStatement;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportBankStatementRequest;
use App\Http\Resources\BankStatementImportResource;
use App\Models\BankStatementImport;

class ImportBankStatementController extends Controller
{
    /**
     * Import a CAMT.053 bank statement and match its credit entries to open invoices.
     *
     * @return BankStatementImportResource
     */
    public function __invoke(ImportBankStatementRequest $request)
    {
        $this->authorize('create', BankStatementImport::class);

        $import = BankStatementImport::importCamt053(
            $request->getStatementContents(),
            $request->getStatementFileName(),
            (int) $request->header('company'),
            $request->user()
        );

        return new BankStatementImportResource($import);
    }
}

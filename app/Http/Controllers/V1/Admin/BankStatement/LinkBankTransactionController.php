<?php

namespace App\Http\Controllers\V1\Admin\BankStatement;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkBankTransactionRequest;
use App\Http\Resources\BankTransactionResource;
use App\Models\BankTransaction;

class LinkBankTransactionController extends Controller
{
    /**
     * Record the bank transaction as payment of the invoices chosen by the user.
     *
     * @return BankTransactionResource
     */
    public function __invoke(LinkBankTransactionRequest $request, BankTransaction $bankTransaction)
    {
        $this->authorize('link', $bankTransaction);

        $bankTransaction->linkToInvoices($request->invoice_ids, $request->user());

        return new BankTransactionResource($bankTransaction->fresh('payments'));
    }
}

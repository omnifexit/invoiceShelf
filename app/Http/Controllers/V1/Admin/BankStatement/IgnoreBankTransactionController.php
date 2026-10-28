<?php

namespace App\Http\Controllers\V1\Admin\BankStatement;

use App\Http\Controllers\Controller;
use App\Http\Requests\IgnoreBankTransactionRequest;
use App\Http\Resources\BankTransactionResource;
use App\Models\BankTransaction;

class IgnoreBankTransactionController extends Controller
{
    /**
     * Mark the bank transaction as not related to any invoice.
     *
     * @return BankTransactionResource
     */
    public function __invoke(IgnoreBankTransactionRequest $request, BankTransaction $bankTransaction)
    {
        $this->authorize('ignore', $bankTransaction);

        $bankTransaction->ignore($request->user());

        return new BankTransactionResource($bankTransaction->fresh());
    }
}

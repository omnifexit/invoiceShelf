<?php

namespace App\Http\Controllers\V1\Admin\BankStatement;

use App\Http\Controllers\Controller;
use App\Http\Resources\BankTransactionResource;
use App\Models\BankTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankTransactionsController extends Controller
{
    /**
     * Display a listing of the imported bank transactions.
     *
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', BankTransaction::class);

        $limit = $request->has('limit') ? $request->limit : 10;

        $transactions = BankTransaction::whereCompany()
            ->with('payments')
            ->applyFilters($request->all())
            ->paginateData($limit);

        return BankTransactionResource::collection($transactions)
            ->additional(['meta' => [
                'review_count' => BankTransaction::whereCompany()
                    ->whereIn('status', BankTransaction::OPEN_STATUSES)
                    ->count(),
            ]]);
    }
}

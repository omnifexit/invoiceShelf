<?php

namespace App\Http\Controllers\V1\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParseInvoiceTextRequest;
use App\Http\Resources\InvoiceDraftResource;
use App\Models\Invoice;

class ParseInvoiceTextController extends Controller
{
    /**
     * Turn a spoken or typed description into an invoice draft. Nothing is saved.
     *
     * @return InvoiceDraftResource
     */
    public function __invoke(ParseInvoiceTextRequest $request)
    {
        $this->authorize('create', Invoice::class);

        return new InvoiceDraftResource(Invoice::draftFromText($request->text));
    }
}

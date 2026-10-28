<?php

namespace App\Http\Controllers\V1\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportInvoiceUblController extends Controller
{
    /**
     * Download the invoice as a UBL 2.1 XML file (Peppol BIS Billing 3.0).
     */
    public function __invoke(Request $request, Invoice $invoice): Response
    {
        $this->authorize('view', $invoice);

        $xml = $invoice->toUbl();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->getUblFileName().'"',
        ]);
    }
}

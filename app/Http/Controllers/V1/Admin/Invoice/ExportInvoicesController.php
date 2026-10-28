<?php

namespace App\Http\Controllers\V1\Admin\Invoice;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportInvoicesController extends Controller
{
    /**
     * Export the (filtered) invoices of the current company as a CSV file.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::whereCompany()
            ->applyFilters($request->except(['page', 'limit']))
            ->with('customer')
            ->latest()
            ->get();

        $fileName = 'invoices-'.Carbon::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($invoices) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, [
                'Invoice Number',
                'Customer Name',
                'Invoice Date',
                'Due Date',
                'Total',
                'Status',
            ], ',', '"', '');

            foreach ($invoices as $invoice) {
                /** @var Customer|null $customer */
                $customer = $invoice->customer;

                fputcsv($handle, [
                    $this->sanitize($invoice->invoice_number),
                    $this->sanitize($customer?->name),
                    $this->formatDate($invoice->invoice_date),
                    $this->formatDate($invoice->due_date),
                    number_format(((int) $invoice->total) / 100, 2, '.', ''),
                    $invoice->status,
                ], ',', '"', '');
            }

            fclose($handle);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function formatDate(mixed $date): string
    {
        return $date ? Carbon::parse($date)->format('Y-m-d') : '';
    }

    /**
     * Prevent CSV/formula injection when the file is opened in a spreadsheet.
     */
    private function sanitize(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}

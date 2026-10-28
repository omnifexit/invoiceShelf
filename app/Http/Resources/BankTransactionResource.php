<?php

namespace App\Http\Resources;

use App\Models\BankTransaction;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankTransaction
 */
class BankTransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'booking_date' => $this->booking_date->format('Y-m-d'),
            'value_date' => $this->value_date?->format('Y-m-d'),
            'amount' => $this->amount,
            'currency_code' => $this->currency_code,
            'counterparty_name' => $this->counterparty_name,
            'counterparty_iban' => $this->counterparty_iban,
            'description' => $this->description,
            'end_to_end_id' => $this->end_to_end_id,
            'status' => $this->status,
            'match_reason' => $this->match_reason,
            'processed_at' => $this->processed_at,
            'candidates' => $this->when(
                in_array($this->status, BankTransaction::OPEN_STATUSES, true),
                fn () => $this->candidateInvoices()->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_name' => $invoice->customer?->getAttribute('name'),
                    'due_amount' => $invoice->due_amount,
                    'total' => $invoice->total,
                    'due_date' => $invoice->due_date,
                    'currency_code' => $this->currency_code,
                ])->values()
            ),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}

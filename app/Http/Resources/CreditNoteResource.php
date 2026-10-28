<?php

namespace App\Http\Resources;

use App\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditNote
 */
class CreditNoteResource extends JsonResource
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
            'credit_note_number' => $this->credit_note_number,
            'credit_note_date' => $this->credit_note_date,
            'amount' => $this->amount,
            'tax' => $this->tax,
            'base_amount' => $this->base_amount,
            'base_tax' => $this->base_tax,
            'exchange_rate' => $this->exchange_rate,
            'notes' => $this->notes,
            'unique_hash' => $this->unique_hash,
            'sequence_number' => $this->sequence_number,
            'invoice_id' => $this->invoice_id,
            'customer_id' => $this->customer_id,
            'company_id' => $this->company_id,
            'currency_id' => $this->currency_id,
            'creator_id' => $this->creator_id,
            'formatted_credit_note_date' => $this->formattedCreditNoteDate,
            'formatted_created_at' => $this->formattedCreatedAt,
            'credit_note_pdf_url' => $this->creditNotePdfUrl,
            'customer' => $this->when($this->customer()->exists(), function () {
                return new CustomerResource($this->customer);
            }),
            'invoice' => $this->when($this->invoice()->exists(), function () {
                return new InvoiceResource($this->invoice);
            }),
            'currency' => $this->when($this->currency()->exists(), function () {
                return new CurrencyResource($this->currency);
            }),
            'company' => $this->when($this->company()->exists(), function () {
                return new CompanyResource($this->company);
            }),
        ];
    }
}

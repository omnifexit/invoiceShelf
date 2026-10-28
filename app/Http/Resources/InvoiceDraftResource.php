<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An unsaved invoice draft built from a spoken or typed description.
 */
class InvoiceDraftResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'text' => $this->resource['text'],
            'customer' => $this->resource['customer'],
            'invoice_date' => $this->resource['invoice_date'],
            'due_date' => $this->resource['due_date'],
            'items' => $this->resource['items'],
            'warnings' => $this->resource['warnings'],
        ];
    }
}

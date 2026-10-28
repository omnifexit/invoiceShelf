<?php

namespace App\Http\Resources;

use App\Models\BankStatementImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BankStatementImport
 */
class BankStatementImportResource extends JsonResource
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
            'file_name' => $this->file_name,
            'statement_id' => $this->statement_id,
            'account_iban' => $this->account_iban,
            'transaction_count' => $this->transaction_count,
            'new_count' => $this->new_count,
            'duplicate_count' => $this->duplicate_count,
            'matched_count' => $this->matched_count,
            'review_count' => $this->review_count,
            'created_at' => $this->created_at,
        ];
    }
}

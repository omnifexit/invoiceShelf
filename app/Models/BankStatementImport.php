<?php

namespace App\Models;

use App\Services\Camt053Parser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function scopeWhereCompany($query)
    {
        $query->where('bank_statement_imports.company_id', request()->header('company'));
    }

    /**
     * Import the credit entries of a CAMT.053 statement. Transactions that were
     * imported before are skipped, new ones are matched to open invoices.
     */
    public static function importCamt053(string $xml, string $fileName, int $companyId, User $user): self
    {
        $parsed = (new Camt053Parser)->parse($xml);

        $import = self::create([
            'company_id' => $companyId,
            'creator_id' => $user->id,
            'file_name' => mb_substr($fileName, 0, 255),
            'file_hash' => hash('sha256', $xml),
            'statement_id' => $parsed['statement_id'],
            'account_iban' => $parsed['account_iban'],
            'transaction_count' => count($parsed['transactions']),
        ]);

        $counts = ['new_count' => 0, 'duplicate_count' => 0, 'matched_count' => 0, 'review_count' => 0];

        foreach ($parsed['transactions'] as $data) {
            $transaction = BankTransaction::importParsed($import, $data, $user);

            if ($transaction === null) {
                $counts['duplicate_count']++;

                continue;
            }

            $counts['new_count']++;

            if ($transaction->status === BankTransaction::STATUS_MATCHED) {
                $counts['matched_count']++;
            } elseif (in_array($transaction->status, BankTransaction::OPEN_STATUSES, true)) {
                $counts['review_count']++;
            }
        }

        $import->update($counts);

        return $import->fresh();
    }
}

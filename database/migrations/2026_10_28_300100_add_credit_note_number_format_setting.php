<?php

use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (Company::all() as $company) {
            if (! CompanySetting::getSetting('credit_note_number_format', $company->id)) {
                CompanySetting::setSettings([
                    'credit_note_number_format' => '{{SERIES:CN}}{{DELIMITER:-}}{{SEQUENCE:6}}',
                ], $company->id);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        CompanySetting::where('option', 'credit_note_number_format')->delete();
    }
};

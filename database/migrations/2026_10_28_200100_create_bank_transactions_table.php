<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id')->unsigned()->nullable();
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unsignedBigInteger('bank_statement_import_id')->nullable();
            $table->foreign('bank_statement_import_id')->references('id')->on('bank_statement_imports')->onDelete('set null');
            $table->string('fingerprint', 64);
            $table->string('account_iban')->nullable();
            $table->date('booking_date');
            $table->date('value_date')->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('currency_code', 3);
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_iban')->nullable();
            $table->text('description')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('bank_reference')->nullable();
            $table->string('status', 20)->default('UNMATCHED');
            $table->string('match_reason')->nullable();
            $table->json('candidate_invoice_ids')->nullable();
            $table->integer('processed_by')->unsigned()->nullable();
            $table->foreign('processed_by')->references('id')->on('users')->onDelete('set null');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'fingerprint']);
            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};

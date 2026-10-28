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
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->date('credit_note_date');
            $table->string('credit_note_number');
            $table->unsignedInteger('sequence_number')->nullable();
            $table->unsignedInteger('customer_sequence_number')->nullable();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('base_amount')->default(0);
            $table->unsignedBigInteger('base_tax')->default(0);
            $table->decimal('exchange_rate', 19, 6)->nullable();
            $table->text('notes')->nullable();
            $table->string('unique_hash')->nullable();
            $table->integer('invoice_id')->unsigned();
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->unsignedBigInteger('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->integer('company_id')->unsigned();
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unsignedInteger('currency_id')->nullable();
            $table->foreign('currency_id')->references('id')->on('currencies');
            $table->unsignedInteger('creator_id')->nullable();
            $table->foreign('creator_id')->references('id')->on('users')->onDelete('set null');
            $table->timestamps();
            $table->unique(['company_id', 'credit_note_number']);
            $table->index(['invoice_id']);
            $table->index(['company_id', 'credit_note_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};

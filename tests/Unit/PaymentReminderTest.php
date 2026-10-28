<?php

use App\Models\Invoice;
use App\Models\PaymentReminder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);
});

test('payment reminder belongs to invoice', function () {
    $reminder = PaymentReminder::factory()->create();

    $this->assertTrue($reminder->invoice()->exists());
});

test('invoice has many payment reminders', function () {
    $invoice = Invoice::factory()->create();
    PaymentReminder::factory()->count(3)->create(['invoice_id' => $invoice->id]);

    $this->assertCount(3, $invoice->paymentReminders);
});

test('latest payment reminder returns the most recent reminder', function () {
    $invoice = Invoice::factory()->create();
    PaymentReminder::factory()->create([
        'invoice_id' => $invoice->id,
        'sent_at' => Carbon::now()->subDays(14),
    ]);
    $latest = PaymentReminder::factory()->create([
        'invoice_id' => $invoice->id,
        'sent_at' => Carbon::now()->subDays(1),
    ]);
    PaymentReminder::factory()->create([
        'invoice_id' => $invoice->id,
        'sent_at' => Carbon::now()->subDays(7),
    ]);

    $this->assertTrue($invoice->latestPaymentReminder->is($latest));
});

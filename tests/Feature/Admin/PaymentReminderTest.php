<?php

use App\Mail\SendInvoiceMail;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->companyId = $user->companies()->first()->id;

    CompanySetting::setSettings(['invoice_email_attachment' => 'NO'], $this->companyId);

    $this->withHeaders([
        'company' => $this->companyId,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );

    Mail::fake();
});

function overdueInvoice(array $attributes = []): Invoice
{
    $customerId = array_key_exists('customer_id', $attributes)
        ? $attributes['customer_id']
        : Customer::factory()->create(['email' => 'klant@example.com'])->id;

    return Invoice::factory()->create(array_merge([
        'status' => Invoice::STATUS_SENT,
        'paid_status' => Invoice::STATUS_UNPAID,
        'total' => 1000,
        'due_amount' => 1000,
        'invoice_date' => Carbon::now()->subDays(40)->toDateString(),
        'due_date' => Carbon::now()->subDays(10)->toDateString(),
    ], $attributes, ['customer_id' => $customerId]));
}

test('sends a payment reminder for an overdue unpaid invoice', function () {
    $invoice = overdueInvoice();

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertSent(SendInvoiceMail::class, function ($mail) use ($invoice) {
        return $mail->hasTo('klant@example.com')
            && str_contains($mail->data['subject'], $invoice->invoice_number);
    });
    $this->assertDatabaseHas('payment_reminders', [
        'invoice_id' => $invoice->id,
        'email' => 'klant@example.com',
    ]);
    $this->assertDatabaseCount('payment_reminders', 1);
});

test('sends a payment reminder for a partially paid overdue invoice', function () {
    $invoice = overdueInvoice([
        'paid_status' => Invoice::STATUS_PARTIALLY_PAID,
        'due_amount' => 500,
    ]);

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertSent(SendInvoiceMail::class, 1);
    $this->assertDatabaseHas('payment_reminders', ['invoice_id' => $invoice->id]);
});

test('does not send a payment reminder for a paid invoice', function () {
    overdueInvoice([
        'status' => Invoice::STATUS_COMPLETED,
        'paid_status' => Invoice::STATUS_PAID,
        'due_amount' => 0,
    ]);

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertNothingSent();
    $this->assertDatabaseCount('payment_reminders', 0);
});

test('does not send a payment reminder for an invoice that is not due yet', function () {
    overdueInvoice(['due_date' => Carbon::now()->addDays(5)->toDateString()]);

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertNothingSent();
    $this->assertDatabaseCount('payment_reminders', 0);
});

test('does not send a payment reminder for a draft invoice', function () {
    overdueInvoice(['status' => Invoice::STATUS_DRAFT]);

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertNothingSent();
    $this->assertDatabaseCount('payment_reminders', 0);
});

test('does not send a payment reminder to a customer without email', function () {
    $customer = Customer::factory()->create(['email' => null]);
    overdueInvoice(['customer_id' => $customer->id]);

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertNothingSent();
    $this->assertDatabaseCount('payment_reminders', 0);
});

test('sends at most one payment reminder per 7 days', function () {
    $invoice = overdueInvoice();
    PaymentReminder::factory()->create([
        'invoice_id' => $invoice->id,
        'sent_at' => Carbon::now()->subDays(3),
    ]);

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertNothingSent();
    $this->assertDatabaseCount('payment_reminders', 1);
});

test('sends a new payment reminder after 7 days', function () {
    $invoice = overdueInvoice();
    PaymentReminder::factory()->create([
        'invoice_id' => $invoice->id,
        'sent_at' => Carbon::now()->subDays(7),
    ]);

    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertSent(SendInvoiceMail::class, 1);
    $this->assertDatabaseCount('payment_reminders', 2);
});

test('running the command twice on the same day sends only one reminder', function () {
    overdueInvoice();

    $this->artisan('send:payment-reminders')->assertSuccessful();
    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertSent(SendInvoiceMail::class, 1);
    $this->assertDatabaseCount('payment_reminders', 1);
});

test('sends the next reminder once 7 days have passed', function () {
    overdueInvoice();

    $this->artisan('send:payment-reminders')->assertSuccessful();

    $this->travel(6)->days();
    $this->artisan('send:payment-reminders')->assertSuccessful();

    Mail::assertSent(SendInvoiceMail::class, 1);

    $this->travel(1)->days();
    $this->artisan('send:payment-reminders')->assertSuccessful();

    $this->travelBack();

    Mail::assertSent(SendInvoiceMail::class, 2);
    $this->assertDatabaseCount('payment_reminders', 2);
});

test('invoice api returns the latest payment reminder', function () {
    $invoice = overdueInvoice();
    PaymentReminder::factory()->create([
        'invoice_id' => $invoice->id,
        'sent_at' => Carbon::now()->subDays(10),
    ]);
    $latest = PaymentReminder::factory()->create([
        'invoice_id' => $invoice->id,
        'sent_at' => Carbon::now()->subDays(2),
    ]);

    $response = getJson("api/v1/invoices/{$invoice->id}")->assertOk();

    $dateFormat = CompanySetting::getSetting('carbon_date_format', $invoice->company_id);

    expect($response->json('data.last_payment_reminder_at'))->not->toBeNull();
    expect(Carbon::parse($response->json('data.last_payment_reminder_at'))->timestamp)
        ->toBe($latest->sent_at->timestamp);
    expect($response->json('data.formatted_last_payment_reminder_at'))
        ->toBe(Carbon::now()->subDays(2)->translatedFormat($dateFormat));
});

test('invoice api returns null when no payment reminder was sent', function () {
    $invoice = overdueInvoice();

    $response = getJson("api/v1/invoices/{$invoice->id}")->assertOk();

    expect($response->json('data.last_payment_reminder_at'))->toBeNull();
    expect($response->json('data.formatted_last_payment_reminder_at'))->toBeNull();
});

test('payment reminders are deleted with their invoice', function () {
    $invoice = overdueInvoice();
    PaymentReminder::factory()->create(['invoice_id' => $invoice->id]);

    $invoice->delete();

    $this->assertDatabaseCount('payment_reminders', 0);
});

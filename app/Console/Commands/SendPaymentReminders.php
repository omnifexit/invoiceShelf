<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendPaymentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:payment-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send payment reminders for overdue unpaid invoices.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sent = 0;

        Invoice::dueForPaymentReminder(Carbon::now())
            ->with('customer')
            ->chunkById(100, function ($invoices) use (&$sent) {
                foreach ($invoices as $invoice) {
                    try {
                        $invoice->sendPaymentReminder();
                        $sent++;
                        $this->info("Payment reminder sent for invoice {$invoice->invoice_number}");
                    } catch (\Throwable $e) {
                        Log::error('Failed to send payment reminder', [
                            'invoice_id' => $invoice->id,
                            'message' => $e->getMessage(),
                        ]);
                        $this->error("Failed to send payment reminder for invoice {$invoice->invoice_number}");
                    }
                }
            }, 'invoices.id', 'id');

        $this->info("{$sent} payment reminder(s) sent.");

        return self::SUCCESS;
    }
}

<?php

namespace Database\Factories;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Services\SerialNumberFormatter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates the credit note record only; the invoice is not adjusted. Feature
 * tests create credit notes through the API instead.
 */
class CreditNoteFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = CreditNote::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $sequenceNumber = (new SerialNumberFormatter)
            ->setModel(new CreditNote)
            ->setCompany(User::find(1)->companies()->first()->id)
            ->setNextNumbers();

        return [
            'invoice_id' => Invoice::factory()->sent(),
            'customer_id' => fn (array $attributes) => Invoice::find($attributes['invoice_id'])->customer_id,
            'currency_id' => fn (array $attributes) => Invoice::find($attributes['invoice_id'])->currency_id,
            'company_id' => fn (array $attributes) => Invoice::find($attributes['invoice_id'])->company_id,
            'credit_note_number' => $sequenceNumber->getNextNumber(),
            'sequence_number' => $sequenceNumber->nextSequenceNumber,
            'customer_sequence_number' => $sequenceNumber->nextCustomerSequenceNumber,
            'credit_note_date' => now()->format('Y-m-d'),
            'amount' => 1,
            'tax' => 0,
            'base_amount' => 1,
            'base_tax' => 0,
            'exchange_rate' => 1,
            'notes' => $this->faker->sentence(),
            'unique_hash' => str_random(60),
            'creator_id' => User::find(1)->id,
        ];
    }
}

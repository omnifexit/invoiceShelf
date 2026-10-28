<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BankTransactionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BankTransaction::class;

    public function ambiguous()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => BankTransaction::STATUS_AMBIGUOUS,
            ];
        });
    }

    public function ignored()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => BankTransaction::STATUS_IGNORED,
            ];
        });
    }

    public function matched()
    {
        return $this->state(function (array $attributes) {
            return [
                'status' => BankTransaction::STATUS_MATCHED,
            ];
        });
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'company_id' => User::find(1)->companies()->first()->id,
            'fingerprint' => hash('sha256', (string) Str::uuid()),
            'account_iban' => 'NL91ABNA0417164300',
            'booking_date' => now()->format('Y-m-d'),
            'amount' => $this->faker->numberBetween(100, 100000),
            'currency_code' => 'EUR',
            'counterparty_name' => $this->faker->company(),
            'description' => $this->faker->sentence(),
            'status' => BankTransaction::STATUS_UNMATCHED,
        ];
    }
}

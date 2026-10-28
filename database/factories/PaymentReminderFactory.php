<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\PaymentReminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class PaymentReminderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = PaymentReminder::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'company_id' => User::find(1)->companies()->first()->id,
            'customer_id' => null,
            'email' => $this->faker->safeEmail(),
            'sent_at' => Carbon::now(),
        ];
    }
}

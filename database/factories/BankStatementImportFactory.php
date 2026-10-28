<?php

namespace Database\Factories;

use App\Models\BankStatementImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BankStatementImportFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BankStatementImport::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'company_id' => User::find(1)->companies()->first()->id,
            'creator_id' => User::find(1)->id,
            'file_name' => 'statement.xml',
            'file_hash' => hash('sha256', $this->faker->uuid()),
            'account_iban' => 'NL91ABNA0417164300',
        ];
    }
}

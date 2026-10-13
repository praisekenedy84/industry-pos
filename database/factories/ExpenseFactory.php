<?php

namespace Database\Factories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'EXP-'.fake()->unique()->numerify('######'),
            'request_id' => fake()->uuid(),
            'expense_date' => now('Africa/Dar_es_Salaam')->toDateString(),
            'category' => 'utilities',
            'description' => 'Electricity bill',
            'payee' => 'Utility provider',
            'amount' => '12500.50',
            'payment_method' => 'cash',
            'status' => 'recorded',
        ];
    }
}

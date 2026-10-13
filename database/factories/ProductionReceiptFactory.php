<?php

namespace Database\Factories;

use App\Models\ProductionReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProductionReceipt> */
class ProductionReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['number' => 'DR-'.fake()->unique()->numerify('######'), 'request_id' => (string) Str::uuid(), 'qty' => '10', 'unit_cost' => '100'];
    }
}

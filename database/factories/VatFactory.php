<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vat>
 */
class VatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'GST ' . fake()->unique()->numberBetween(1, 99999),
            'business_id' => 1,
            'rate' => 18,
            'status' => 1,
        ];
    }
}

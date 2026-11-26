<?php

namespace Database\Factories;

use App\Models\BusinessCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Business>
 */
class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companyName' => fake()->company(),
            'email' => fake()->companyEmail(),
            'phoneNumber' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'business_category_id' => BusinessCategory::factory(),
            'status' => 1,
        ];
    }
}

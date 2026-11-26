<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'productName' => 'Product ' . fake()->unique()->uuid(),
            'business_id' => 1,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'productDealerPrice' => 0,
            'productWholeSalePrice' => 0,
            'productStock' => 0,
            'vat_type' => 'exclusive',
            'conversionValue' => 1,
        ];
    }
}

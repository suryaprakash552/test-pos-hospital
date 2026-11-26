<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stock>
 */
class StockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => 1,
            'product_id' => 1,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'productDealerPrice' => 0,
            'productWholeSalePrice' => 0,
            'productStock' => 100,
            'conversionValue' => 1,
        ];
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selling stock has to change what the catalogue reports.
 *
 * The product list is cached for an hour under `TAG_PRODUCTS`, and the sale,
 * sale-return, purchase-return and stock-adjustment controllers cleared nothing
 * — only the product controller's own store/update/destroy did. So a product
 * sold down to zero went on reading as in stock for up to an hour, and the
 * batch sheet behind it disagreed with the card in front of it.
 */
class StockFreshnessAfterSaleTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Product $product;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'batch_no' => 'B1',
            'productStock' => 10,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 600,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 10,
            'conversionQuantity' => 10,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /** What the catalogue says is left of the batch. */
    private function listedAvailable(): float
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertSuccessful();

        foreach ($response->json('data.data') as $product) {
            foreach ($product['stocks'] ?? [] as $row) {
                if ((int) $row['id'] === (int) $this->stock->id) {
                    return (float) $row['available_quantity'];
                }
            }
        }

        return -1;
    }

    private function sell(float $quantity): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'products' => json_encode([[
                    'stock_id' => $this->stock->id,
                    'quantities' => $quantity,
                    'lossProfit' => 0,
                ]]),
                'saleDate' => now()->toDateString(),
                'paymentType' => 'cash',
                'totalAmount' => 0,
                'paidAmount' => 10000,
            ])
            ->assertSuccessful();
    }

    public function test_the_list_reports_the_stock_left_after_a_sale(): void
    {
        // Warms the cache, which is what made the next read stale.
        $this->assertSame(10.0, $this->listedAvailable());

        $this->sell(4);

        $this->assertSame(6.0, $this->listedAvailable(),
            'the catalogue served the stock from before the sale');
    }

    public function test_selling_the_last_of_it_reads_as_none_left(): void
    {
        $this->assertSame(10.0, $this->listedAvailable());

        $this->sell(10);

        $this->assertSame(0.0, $this->listedAvailable(),
            'a product sold out still read as in stock');
    }
}

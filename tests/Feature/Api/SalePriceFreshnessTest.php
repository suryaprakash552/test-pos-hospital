<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Bug 16: "the sale price does not match until the purchase bill is re-entered".
 *
 * Written to reproduce, not to fix. Re-entering the purchase is what rewrites the
 * *batch*, which is the clue: the cart prices from `stocks`, not from `products`,
 * so a product edit that stopped at the product row would behave exactly as
 * reported - correct on the product screen, stale at the till, and fixed by a new
 * purchase.
 *
 * These follow the reported sequence: edit the price, then read it back through
 * the list, then actually sell one and check what the sale recorded.
 */
class SalePriceFreshnessTest extends TestCase
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
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 6000,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 100,
            'conversionQuantity' => 100,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    private function changeSalePriceTo(float $price): void
    {
        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$this->product->id}", [
                'productName' => 'Widget',
                'productSalePrice' => $price,
            ])
            ->assertSuccessful();
    }

    /** What the product list reports for the batch the till prices from. */
    private function listedBatchPrice(): float
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertSuccessful();

        foreach ($response->json('data.data') as $product) {
            foreach ($product['stocks'] ?? [] as $row) {
                if ((int) $row['id'] === (int) $this->stock->id) {
                    return (float) $row['productSalePrice'];
                }
            }
        }

        return -1;
    }

    private function sellOne(): float
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'products' => json_encode([[
                    'stock_id' => $this->stock->id,
                    'quantities' => 1,
                    'lossProfit' => 0,
                ]]),
                'saleDate' => now()->toDateString(),
                'paymentType' => 'cash',
                'totalAmount' => 0,
                'paidAmount' => 10000,
            ])
            ->assertSuccessful();

        return (float) SaleDetails::latest('id')->first()->price;
    }

    public function test_a_price_change_reaches_the_batch_the_till_prices_from(): void
    {
        $this->changeSalePriceTo(150);

        $this->assertSame(
            150.0,
            (float) $this->stock->fresh()->productSalePrice,
            'The cart prices from the batch; a change that stops at the product '
                . 'would only appear after a new purchase rewrote the batch.',
        );
    }

    public function test_the_product_list_reports_the_new_price_immediately(): void
    {
        $this->changeSalePriceTo(150);

        $this->assertSame(150.0, $this->listedBatchPrice());
    }

    public function test_a_sale_taken_straight_after_the_edit_uses_the_new_price(): void
    {
        $this->changeSalePriceTo(150);

        $this->assertSame(150.0, $this->sellOne(), 'No re-entered purchase required.');
    }

    public function test_two_successive_changes_both_take_effect(): void
    {
        $this->changeSalePriceTo(150);
        $this->assertSame(150.0, $this->sellOne());

        $this->changeSalePriceTo(175);
        $this->assertSame(175.0, $this->sellOne());
    }

    /**
     * The list is cached, so a stale read is a plausible cause of the report.
     *
     * The cache is warmed first, deliberately: invalidation only matters once
     * something has been stored.
     */
    public function test_a_warmed_product_cache_does_not_serve_the_old_price(): void
    {
        config(['cache.default' => 'file']);
        Cache::store('file')->flush();

        $this->assertSame(100.0, $this->listedBatchPrice(), 'warm the cache');

        $this->changeSalePriceTo(150);

        $this->assertSame(
            150.0,
            $this->listedBatchPrice(),
            'A cached list outliving the edit is exactly what "stale until '
                . 'something else rewrites it" looks like.',
        );
    }

    public function test_an_edit_that_omits_the_price_leaves_it_alone(): void
    {
        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$this->product->id}", [
                'productName' => 'Renamed Widget',
            ])
            ->assertSuccessful();

        $this->assertSame(
            100.0,
            (float) $this->stock->fresh()->productSalePrice,
            'Renaming a product must not reset its batch prices to zero.',
        );
    }

    public function test_an_edit_cannot_replace_a_billable_price_with_zero(): void
    {
        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$this->product->id}", [
                'productName' => 'Widget',
                'productSalePrice' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('productSalePrice');

        $this->assertSame(100.0, (float) $this->product->fresh()->productSalePrice);
        $this->assertSame(100.0, (float) $this->stock->fresh()->productSalePrice);
    }

    public function test_the_price_survives_a_fresh_read_of_the_sale(): void
    {
        $this->changeSalePriceTo(150);
        $this->sellOne();

        $sale = Sale::latest('id')->first();

        $this->assertSame(150.0, (float) $sale->totalAmount);
    }
}

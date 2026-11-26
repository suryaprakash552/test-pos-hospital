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
 * Two screens share this catalogue and want different orders.
 *
 * Counter Sale, Sales and Purchase want the stock that arrived most recently,
 * which is what `latest_purchase_at` gives them. The Inventory list is where a
 * product is created, and there the useful answer is the one just added — which
 * may have no stock at all yet and would otherwise sort last.
 */
class ProductListOrderTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
    }

    private function product(string $name): Product
    {
        return Product::factory()->create([
            'business_id' => $this->business->id,
            'productName' => $name,
        ]);
    }

    /** Give a product a delivery, so it has a `latest_purchase_at`. */
    private function restock(Product $product): void
    {
        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 100,
            'paidAmount' => 100,
            'dueAmount' => 0,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 10,
            'conversionQuantity' => 10,
            'conversionValue' => 1,
            'productPurchasePrice' => 10,
            'productSalePrice' => 20,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /** @return list<string> */
    private function names(array $query = []): array
    {
        $json = $this->actingAs($this->user)
            ->getJson('/api/v1/products?'.http_build_query($query))
            ->assertStatus(200)
            ->json();

        // The endpoint paginates, so the rows sit under data.data.
        $rows = data_get($json, 'data.data') ?? data_get($json, 'data') ?? [];

        return collect($rows)->pluck('productName')->all();
    }

    public function test_the_inventory_list_puts_the_newest_product_first(): void
    {
        $old = $this->product('Older product');
        $this->restock($old);

        // Created after, and never restocked — so it has no purchase date at
        // all, which is exactly the case that used to sort it last.
        $this->product('Just added');

        $this->assertSame('Just added', $this->names(['sort' => 'newest'])[0]);
    }

    public function test_the_till_still_gets_the_most_recently_restocked_first(): void
    {
        $restocked = $this->product('Restocked today');
        $this->product('Added later, never stocked');
        $this->restock($restocked);

        $this->assertSame('Restocked today', $this->names()[0],
            'the counter order must not change');
    }

    public function test_an_unknown_sort_falls_back_to_the_default(): void
    {
        $restocked = $this->product('Restocked today');
        $this->product('Added later, never stocked');
        $this->restock($restocked);

        $this->assertSame('Restocked today', $this->names(['sort' => 'nonsense'])[0]);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The product list's summary cards.
 *
 * They were counted in the app by re-filtering whichever product list happened to
 * be in memory. Choosing a category put the grid into server-side paging while the
 * counts kept filtering a separate unpaginated list on `category.id` - so they
 * disagreed with the grid, and dropped any product whose category relation was
 * missing from the payload.
 */
class ProductStockSummaryTest extends TestCase
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

    /**
     * A product holding `$quantity` sellable units.
     */
    private function product(float $quantity, ?Category $category = null, float $alertQty = 0): Product
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => $category?->id,
            'alert_qty' => $alertQty,
        ]);

        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 0,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => $quantity,
            // The measure the sale endpoint validates against.
            'conversionQuantity' => $quantity,
            'conversionValue' => 1,
            'productPurchasePrice' => 10,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        return $product;
    }

    private function summary(array $query = [])
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/stocks/summary?'.http_build_query($query));
    }

    public function test_it_counts_the_whole_catalogue(): void
    {
        $this->product(50);            // in stock
        $this->product(0);             // out of stock
        $this->product(3, alertQty: 5); // low stock

        $this->summary()
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.in_stock', 1)
            ->assertJsonPath('data.low_stock', 1)
            ->assertJsonPath('data.out_of_stock', 1);
    }

    public function test_the_four_figures_always_reconcile(): void
    {
        foreach ([0, 0, 12, 40, 2] as $quantity) {
            $this->product($quantity, alertQty: 5);
        }

        $data = $this->summary()->json('data');

        $this->assertSame(
            $data['total'],
            $data['in_stock'] + $data['low_stock'] + $data['out_of_stock'],
            'The cards must add up to the total.',
        );
    }

    public function test_a_category_narrows_the_counts(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->product(10, $category);
        $this->product(0, $category);
        // Belongs to no category, so must not be counted under one.
        $this->product(10);

        $this->summary(['category_id' => $category->id])
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.in_stock', 1)
            ->assertJsonPath('data.out_of_stock', 1);
    }

    public function test_a_product_with_no_alert_threshold_is_never_low(): void
    {
        // A null/zero threshold means "none set", not "warn at zero".
        $this->product(1, alertQty: 0);

        $this->summary()
            ->assertOk()
            ->assertJsonPath('data.low_stock', 0)
            ->assertJsonPath('data.in_stock', 1);
    }

    public function test_another_tenants_products_are_not_counted(): void
    {
        $this->product(10);

        $otherBusiness = Business::factory()->create();
        Product::factory()->count(4)->create(['business_id' => $otherBusiness->id]);

        $this->summary()->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_another_tenants_category_is_refused(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignCategory = Category::factory()->create(['business_id' => $otherBusiness->id]);

        $this->summary(['category_id' => $foreignCategory->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_a_search_term_does_not_change_the_counts(): void
    {
        $this->product(10);
        $this->product(0);

        // The cards describe the catalogue, not the result set: passing a search
        // term must leave them alone.
        $withSearch = $this->summary(['search' => 'something'])->json('data');
        $without = $this->summary()->json('data');

        $this->assertSame($without, $withSearch);
    }

    public function test_product_stock_status_filters_before_pagination(): void
    {
        $in = $this->product(50, alertQty: 5);
        $low = $this->product(3, alertQty: 5);
        $out = $this->product(0, alertQty: 5);

        foreach ([
            'in_stock' => $in->id,
            'low_stock' => $low->id,
            'out_of_stock' => $out->id,
        ] as $status => $expectedId) {
            $response = $this->actingAs($this->user)->getJson(
                '/api/v1/products?'.http_build_query([
                    'stock_status' => $status,
                    'per_page' => 1,
                    'page' => 1,
                ]),
            );

            $response->assertOk()
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.data.0.id', $expectedId);
        }
    }
    /**
     * The card and the filter must agree.
     *
     * `products.productStock` is a cached column that drifts from the batches.
     * The list rendered it, while the filter and the summary counted the batch
     * sums — so a product the server correctly returned under "Low Stock" drew
     * itself as "Out Of Stock · 0 Units" on the very same screen. The row now
     * carries the computed figure, from the same source as everything else.
     */
    public function test_a_row_carries_the_quantity_the_filter_counted(): void
    {
        $product = $this->product(6, alertQty: 20);

        // Exactly the drift that produced the wrong card.
        $product->forceFill(['productStock' => 0])->save();

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/products?'.http_build_query(['stock_status' => 'low_stock']),
        )->assertOk();

        $this->assertSame($product->id, $response->json('data.data.0.id'));
        $this->assertEquals(6, $response->json('data.data.0.available_quantity'));
    }
}

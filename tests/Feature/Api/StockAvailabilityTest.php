<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `purchase_details.conversionQuantity` holds what is *left* of a purchase lot, not
 * what was bought — AcnooSaleController draws it down FIFO, zeroing each lot before
 * moving to the next. A batch's sellable quantity is therefore the sum across all
 * lots, which is exactly what the sale endpoint checks before accepting an order.
 *
 * The app used to read a single lot's remainder instead, so once the oldest lot hit
 * zero — which FIFO guarantees — a batch holding stock reported itself empty. These
 * tests pin the server-side figure the app now consumes.
 */
class StockAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;
    protected Product $product;
    protected Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->product = Product::create([
            'productName' => 'IV Set',
            'business_id' => $this->business->id,
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id'  => $this->product->id,
            'batch_no'    => 'B1',
            'productStock' => 10,
        ]);
    }

    /** Adds a purchase lot with the given remaining quantity. */
    private function lot(float $remaining): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id' => $this->business->id,
            'totalAmount' => 100,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return DB::table('purchase_details')->insertGetId([
            'purchase_id'        => $purchaseId,
            'product_id'         => $this->product->id,
            'stock_id'           => $this->stock->id,
            'conversionQuantity' => $remaining,
            'quantities'         => $remaining,
            // Non-nullable and without defaults in the schema.
            'purchaseUpperLimit' => '',
            'purchaseLowerLimit' => '',
        ]);
    }

    public function test_availability_is_the_sum_across_all_lots(): void
    {
        $this->lot(3);
        $this->lot(7);

        $this->assertSame(10.0, $this->stock->fresh()->available_quantity);
    }

    /**
     * The exact failure users reported: FIFO empties the oldest lot, and the batch
     * was then treated as out of stock despite later lots holding quantity.
     */
    public function test_a_depleted_first_lot_does_not_hide_remaining_stock(): void
    {
        $this->lot(0);   // drawn down by earlier sales
        $this->lot(25);  // still sellable

        $available = $this->stock->fresh()->available_quantity;

        $this->assertSame(25.0, $available);
        $this->assertGreaterThan(0, $available, 'the batch must not report itself empty');
    }

    public function test_a_batch_with_every_lot_depleted_is_zero(): void
    {
        $this->lot(0);
        $this->lot(0);

        $this->assertSame(0.0, $this->stock->fresh()->available_quantity);
    }

    public function test_a_batch_with_no_lots_is_zero(): void
    {
        $this->assertSame(0.0, $this->stock->fresh()->available_quantity);
    }

    /**
     * The eager-loaded sum and the lazy fallback must agree, otherwise availability
     * would depend on which endpoint happened to serve the batch.
     */
    public function test_the_eager_loaded_sum_matches_the_lazy_fallback(): void
    {
        $this->lot(0);
        $this->lot(4);
        $this->lot(6);

        $lazy = Stock::find($this->stock->id)->available_quantity;

        $eager = Stock::withSum('availableLots as available_quantity', 'conversionQuantity')
            ->find($this->stock->id)
            ->available_quantity;

        $this->assertSame(10.0, $lazy);
        $this->assertSame($lazy, $eager);
    }

    public function test_availability_is_serialized_with_the_batch(): void
    {
        $this->lot(0);
        $this->lot(12);

        $this->assertSame(12.0, $this->stock->fresh()->toArray()['available_quantity']);
    }

    public function test_the_products_endpoint_exposes_availability_per_batch(): void
    {
        $this->lot(0);
        $this->lot(9);

        $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.stocks.0.available_quantity', 9);
    }

    /** Records a sale drawn from this batch. */
    private function sold(float $quantity): void
    {
        $saleId = DB::table('sales')->insertGetId([
            'business_id' => $this->business->id,
            'totalAmount' => 10,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('sale_details')->insert([
            'sale_id'    => $saleId,
            'product_id' => $this->product->id,
            'stock_id'   => $this->stock->id,
            'quantities' => $quantity,
            'price'      => 10,
        ]);
    }

    public function test_sold_quantity_sums_the_batches_sales(): void
    {
        $this->sold(4);
        $this->sold(6);

        $this->assertSame(10.0, $this->stock->fresh()->sold_quantity);
    }

    public function test_a_batch_never_sold_reports_zero(): void
    {
        $this->lot(5);

        $this->assertSame(0.0, $this->stock->fresh()->sold_quantity);
    }

    /**
     * This is the floor for editing a purchase: the batch cannot be reduced below
     * what has already left the shelf.
     */
    public function test_sold_quantity_is_exposed_to_clients(): void
    {
        $this->lot(3);
        $this->sold(7);

        $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.stocks.0.sold_quantity', 7)
            ->assertJsonPath('data.data.0.stocks.0.available_quantity', 3);
    }

    public function test_the_eager_loaded_sold_sum_matches_the_lazy_fallback(): void
    {
        $this->sold(2);
        $this->sold(3);

        $lazy = Stock::find($this->stock->id)->sold_quantity;

        $eager = Stock::withSum('saleDetails as sold_quantity', 'quantities')
            ->find($this->stock->id)
            ->sold_quantity;

        $this->assertSame(5.0, $lazy);
        $this->assertSame($lazy, $eager);
    }

    /**
     * Availability must mean the same thing to the client as it does to the sale
     * endpoint, or the app will offer quantities the server then rejects.
     */
    public function test_availability_matches_what_the_sale_endpoint_enforces(): void
    {
        $this->lot(0);
        $this->lot(5);

        $serverSideCheck = DB::table('purchase_details')
            ->where('stock_id', $this->stock->id)
            ->where('conversionQuantity', '>', 0)
            ->sum('conversionQuantity');

        $this->assertEquals(
            (float) $serverSideCheck,
            $this->stock->fresh()->available_quantity,
            'the exposed figure must equal the one AcnooSaleController validates against'
        );
    }
}

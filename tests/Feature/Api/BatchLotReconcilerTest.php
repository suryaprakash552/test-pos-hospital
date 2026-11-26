<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Services\BatchLotReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Editing a purchase deletes its detail rows and re-inserts them at the submitted
 * quantities, which resets every lot to full and silently un-sells anything already
 * sold. Because of that, edits used to be refused as soon as a batch had any sales.
 *
 * The reconciler restores `remaining = purchased - sold` after the rewrite, so an
 * edit is only refused when it would take a batch below what has already left the
 * shelf — which is impossible rather than merely inconvenient.
 */
class BatchLotReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Product $product;
    protected Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->product = Product::create([
            'productName' => 'IV Set',
            'business_id' => $this->business->id,
        ]);

        $this->stock = Stock::create([
            'business_id'     => $this->business->id,
            'product_id'      => $this->product->id,
            'batch_no'        => 'B1',
            'conversionValue' => 10,
        ]);
    }

    /**
     * @param float $upperQty      quantity in the upper unit
     * @param float $remaining     lot remainder in conversion units
     */
    private function lot(float $upperQty, float $remaining, float $conversionValue = 10): int
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
            'quantities'         => $upperQty,
            'conversionValue'    => $conversionValue,
            'conversionQuantity' => $remaining,
            'purchaseUpperLimit' => '',
            'purchaseLowerLimit' => '',
        ]);
    }

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

    private function remainders(): array
    {
        return DB::table('purchase_details')
            ->where('stock_id', $this->stock->id)
            ->orderBy('id')
            ->pluck('conversionQuantity')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    public function test_purchased_counts_every_lot_in_conversion_units(): void
    {
        $this->lot(upperQty: 10, remaining: 100);  // 10 x 10 = 100
        $this->lot(upperQty: 5, remaining: 50);    //  5 x 10 =  50

        $this->assertSame(150.0, BatchLotReconciler::purchasedFor($this->stock->id));
    }

    public function test_a_batch_with_no_sales_has_no_shortfall(): void
    {
        $this->lot(upperQty: 10, remaining: 100);

        $this->assertNull(BatchLotReconciler::shortfall($this->stock->id));
    }

    public function test_a_shortfall_is_reported_when_purchases_no_longer_cover_sales(): void
    {
        $this->lot(upperQty: 5, remaining: 50);   // 50 purchased
        $this->sold(80);                          // but 80 already sold

        $shortfall = BatchLotReconciler::shortfall($this->stock->id);

        $this->assertNotNull($shortfall);
        $this->assertSame(80.0, $shortfall['sold']);
        $this->assertSame(50.0, $shortfall['purchased']);
        $this->assertSame(30.0, $shortfall['shortfall']);
    }

    /**
     * The rewrite that used to lose consumption: both lots come back full, and
     * replaying must draw the sold quantity back out of them, oldest first.
     */
    public function test_replay_draws_sales_down_the_lots_oldest_first(): void
    {
        $this->lot(upperQty: 10, remaining: 100);  // re-inserted at full
        $this->lot(upperQty: 10, remaining: 100);  // re-inserted at full
        $this->sold(130);

        BatchLotReconciler::replay($this->stock->id);

        // 130 sold: the first lot (100) is emptied, 30 comes out of the second.
        $this->assertSame([0.0, 70.0], $this->remainders());
    }

    public function test_replay_leaves_an_unsold_batch_untouched(): void
    {
        $this->lot(upperQty: 10, remaining: 100);

        BatchLotReconciler::replay($this->stock->id);

        $this->assertSame([100.0], $this->remainders());
    }

    public function test_replay_is_repeatable(): void
    {
        $this->lot(upperQty: 10, remaining: 100);
        $this->lot(upperQty: 10, remaining: 100);
        $this->sold(130);

        BatchLotReconciler::replay($this->stock->id);
        $first = $this->remainders();

        BatchLotReconciler::replay($this->stock->id);

        $this->assertSame($first, $this->remainders(),
            'replay derives remainders from stored quantities, so it must be idempotent');
    }

    /**
     * The invariant the whole thing exists to maintain.
     */
    public function test_remaining_equals_purchased_minus_sold_after_replay(): void
    {
        $this->lot(upperQty: 8, remaining: 80);
        $this->lot(upperQty: 4, remaining: 40);
        $this->sold(45);

        BatchLotReconciler::replay($this->stock->id);

        $purchased = BatchLotReconciler::purchasedFor($this->stock->id);
        $sold      = BatchLotReconciler::soldFor($this->stock->id);

        $this->assertSame($purchased - $sold, array_sum($this->remainders()));
        $this->assertSame(75.0, array_sum($this->remainders()));
    }

    /**
     * A batch is keyed by product + batch number, so several purchases feed one.
     * The comparison has to be batch-wide, or reducing one purchase would look
     * illegal even when another purchase still covers the sales.
     */
    public function test_sales_are_covered_across_purchases_not_per_purchase(): void
    {
        $this->lot(upperQty: 3, remaining: 30);   // purchase A
        $this->lot(upperQty: 9, remaining: 90);   // purchase B
        $this->sold(60);

        $this->assertNull(
            BatchLotReconciler::shortfall($this->stock->id),
            '120 purchased across both covers 60 sold, even though A alone does not'
        );
    }

    public function test_a_zero_conversion_value_is_treated_as_one(): void
    {
        $this->lot(upperQty: 7, remaining: 7, conversionValue: 0);

        $this->assertSame(7.0, BatchLotReconciler::purchasedFor($this->stock->id));
    }
}

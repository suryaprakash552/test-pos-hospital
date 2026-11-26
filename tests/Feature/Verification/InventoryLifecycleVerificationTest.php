<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pre-deployment verification against a real MySQL database.
 *
 * Not a unit test: each case performs an actual operation through the API and
 * then reads the tables back, because the whole point is to prove the recorded
 * state is right rather than that a function returned the right value.
 *
 * The invariant under test throughout:
 *
 *   SUM(purchase_details.conversionQuantity / conversionValue)
 *     = stocks.productStock                 (per batch)
 *     = products.productStock               (per product, across batches)
 *
 * Division by each lot's own conversionValue matters: a product held in strips
 * of 10 and strips of 5 cannot be totalled by a plain SUM.
 */
class InventoryLifecycleVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Customer $customer;
    private Category $category;

    /** Evidence printed at the end of the run. */
    private static array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id,
            'name' => 'E2E_VERIFY Supplier',
            'type' => 'Supplier',
            'due' => 0,
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'E2E_VERIFY Customer A',
            'phone' => '9000000101',
            'relationship' => 'self',
        ]);

        $this->actingAs($this->user);
    }

    private function record(string $key, array $data): void
    {
        self::$evidence[$key] = $data;
        fwrite(STDERR, "\n[EVIDENCE] {$key}: ".json_encode($data)."\n");
    }

    private function product(string $code, float $conversionValue = 1): Product
    {
        return Product::create([
            'productName' => 'E2E_VERIFY '.$code,
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'productCode' => $code,
            'productStock' => 0,
            'conversionValue' => $conversionValue,
        ]);
    }

    private function buy(Product $product, array $overrides = [])
    {
        return $this->postJson('/api/v1/purchase', [
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [array_merge([
                'product_id' => $product->id,
                'batch_no' => 'E2E-B1',
                'quantities' => 10,
                'productPurchasePrice' => 50,
                'productSalePrice' => 80,
                'conversion_purchase_price' => 50,
                'conversion_quantities' => 10,
                'conversion_value' => 1,
                'conversion_price' => 80,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1,
                'lower_selected_unit' => 1,
                'total_purchase_price' => 500,
                'total_price' => 800,
            ], $overrides)],
        ]);
    }

    /** Authoritative lower-unit balance for a batch. */
    private function lots(int $stockId): float
    {
        return (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity');
    }

    /** Authoritative balance converted to whole stock units, per lot. */
    private function authoritativeUpper(int $stockId): float
    {
        return (float) DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->selectRaw('COALESCE(SUM(conversionQuantity / GREATEST(COALESCE(NULLIF(conversionValue,0),1),1)),0) t')
            ->value('t');
    }

    private function batchTally(int $stockId): float
    {
        return (float) DB::table('stocks')->where('id', $stockId)->value('productStock');
    }

    private function productTally(int $productId): float
    {
        return (float) DB::table('products')->where('id', $productId)->value('productStock');
    }

    private function assertInvariant(int $stockId, int $productId, string $stage): void
    {
        $auth = round($this->authoritativeUpper($stockId), 4);
        $batch = round($this->batchTally($stockId), 4);
        $prod = round($this->productTally($productId), 4);

        $this->record("invariant/{$stage}", [
            'authoritative_upper' => $auth,
            'stocks.productStock' => $batch,
            'products.productStock' => $prod,
            'lots_lower' => $this->lots($stockId),
        ]);

        $this->assertSame($auth, $batch, "batch tally drifted at {$stage}");
        $this->assertSame($auth, $prod, "product tally drifted at {$stage}");
        $this->assertGreaterThanOrEqual(0, $this->lots($stockId), "negative lot balance at {$stage}");
    }

    // ══════════════════════════════════════════════════════════════════════

    public function test_the_full_lifecycle_keeps_the_derived_tallies_true(): void
    {
        $product = $this->product('LIFECYCLE');

        // ── PURCHASE ──────────────────────────────────────────────────────
        $this->buy($product)->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');
        $this->assertInvariant($stockId, $product->id, 'after_purchase');
        $this->assertSame(10.0, $this->lots($stockId));

        // ── SALE ──────────────────────────────────────────────────────────
        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => 320,
            'paidAmount' => 320,
            'products' => json_encode([[
                'stock_id' => $stockId,
                'quantities' => 4,
                'price' => 80,
                'lossProfit' => 30,
            ]]),
        ])->assertSuccessful();

        $this->assertInvariant($stockId, $product->id, 'after_sale');
        $this->assertSame(6.0, $this->lots($stockId), 'a sale of 4 from 10 must leave 6');

        $saleId = (int) DB::table('sales')->where('business_id', $this->business->id)->value('id');

        // Per-lot event with the lot named in its own column.
        $saleEvents = InventoryChangeLog::where('source_id', $saleId)
            ->where('event_type', 'sale_out')->get();

        $this->record('sale_events', [
            'count' => $saleEvents->count(),
            'purchase_detail_id' => $saleEvents->pluck('purchase_detail_id')->all(),
            'quantity_delta' => $saleEvents->pluck('quantity_delta')->map(fn ($d) => (float) $d)->all(),
            'correlation' => $saleEvents->pluck('correlation_id')->unique()->values()->all(),
        ]);

        $this->assertCount(1, $saleEvents);
        $this->assertNotNull($saleEvents[0]->purchase_detail_id);
        $this->assertSame(-4.0, (float) $saleEvents[0]->quantity_delta);

        // ── PARTIAL SALE RETURN ───────────────────────────────────────────
        $saleDetailId = (int) DB::table('sale_details')->where('sale_id', $saleId)->value('id');

        $returned = $this->postJson('/api/v1/sales-return', [
            'sale_id' => $saleId,
            'return_date' => now()->toDateString(),
            'sale_detail_id' => [$saleDetailId],
            'return_qty' => [1],
            'return_amount' => [80],
        ]);

        $this->record('sale_return_status', ['status' => $returned->status()]);

        if ($returned->status() === 200) {
            $this->assertInvariant($stockId, $product->id, 'after_partial_sale_return');
            $this->assertSame(7.0, $this->lots($stockId), 'returning 1 of 4 must restore to 7');
        }

        // ── PURCHASE RETURN ───────────────────────────────────────────────
        $purchaseDetailId = (int) DB::table('purchase_details')->where('stock_id', $stockId)->value('id');
        $purchaseId = (int) DB::table('purchases')->where('business_id', $this->business->id)->value('id');

        $pReturn = $this->postJson('/api/v1/purchases-return', [
            'purchase_id' => $purchaseId,
            'return_date' => now()->toDateString(),
            'purchase_detail_id' => [$purchaseDetailId],
            'return_qty' => [2],
            'return_amount' => [100],
        ]);

        $this->record('purchase_return_status', ['status' => $pReturn->status()]);
        $pReturn->assertStatus(200);

        $this->assertInvariant($stockId, $product->id, 'after_purchase_return');
        $this->assertSame(5.0, $this->lots($stockId), 'returning 2 to the supplier must leave 5');

        // ── DISPOSAL ──────────────────────────────────────────────────────
        DB::table('stocks')->where('id', $stockId)->update(['expire_date' => now()->subDay()->toDateString()]);

        $pending = $this->getJson('/api/v1/expired-stock/pending')->assertSuccessful();
        $rows = $pending->json('data') ?? [];
        // Rows are Stock models, so the key is `id`.
        $ids = collect($rows)->pluck('id')->filter()->map(fn ($v) => (int) $v)->all();

        $this->record('disposal_pending', [
            'returned_stock_ids' => $ids,
            'looking_for' => $stockId,
            'row_count' => count($rows),
            'stock_row' => (array) DB::table('stocks')->where('id', $stockId)
                ->first(['id', 'business_id', 'expire_date', 'productStock']),
            'lots' => DB::table('purchase_details')->where('stock_id', $stockId)
                ->get(['id', 'conversionQuantity'])->map(fn ($r) => (array) $r)->all(),
            'today' => now()->toDateString(),
        ]);

        $this->assertContains($stockId, $ids, 'an expired batch holding stock must be offered for disposal');

        $this->postJson('/api/v1/stock-disposals', [
            'stock_ids' => [$stockId],
            'reason' => 'E2E_VERIFY expired disposal',
        ], ['Idempotency-Key' => 'e2e-'.uniqid()])->assertSuccessful();

        $this->assertInvariant($stockId, $product->id, 'after_disposal');
        $this->assertSame(0.0, $this->lots($stockId), 'disposal must drain the lots');

        // The whole lineage, in order, for one batch.
        $trail = InventoryChangeLog::where('stock_id', $stockId)
            ->orderBy('id')
            ->get(['event_type', 'purchase_detail_id', 'quantity_delta', 'source_type']);

        $this->record('event_trail', [
            'sequence' => $trail->pluck('event_type')->all(),
            'deltas' => $trail->pluck('quantity_delta')->map(fn ($d) => (float) $d)->all(),
            'lot_named' => $trail->pluck('purchase_detail_id')->map(fn ($v) => $v === null ? null : (int) $v)->all(),
        ]);

        $this->assertGreaterThan(0, $trail->count(), 'the batch must have an event history');
    }

    public function test_multi_lot_fifo_allocates_and_restores_per_lot(): void
    {
        $product = $this->product('FIFO');

        // Lot A (older) 30, Lot B (newer) 40 in the same batch.
        $this->buy($product, ['quantities' => 30, 'conversion_quantities' => 30])->assertStatus(200);
        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');
        $this->buy($product, ['quantities' => 40, 'conversion_quantities' => 40, 'stock_id' => $stockId])->assertStatus(200);

        $lotIds = DB::table('purchase_details')->where('stock_id', $stockId)->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $lotIds);
        [$lotA, $lotB] = $lotIds;

        $this->record('fifo/before', [
            'lotA' => [$lotA, (float) DB::table('purchase_details')->where('id', $lotA)->value('conversionQuantity')],
            'lotB' => [$lotB, (float) DB::table('purchase_details')->where('id', $lotB)->value('conversionQuantity')],
        ]);

        // Sell 50: must take 30 from A then 20 from B.
        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => 4000,
            'paidAmount' => 4000,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 50, 'price' => 80, 'lossProfit' => 30,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->where('business_id', $this->business->id)->value('id');

        $events = InventoryChangeLog::where('source_id', $saleId)
            ->where('event_type', 'sale_out')->orderBy('id')->get();

        $this->record('fifo/events', [
            'order' => $events->pluck('purchase_detail_id')->map(fn ($v) => (int) $v)->all(),
            'deltas' => $events->pluck('quantity_delta')->map(fn ($v) => (float) $v)->all(),
            'correlations' => $events->pluck('correlation_id')->unique()->count(),
            'lotA_after' => (float) DB::table('purchase_details')->where('id', $lotA)->value('conversionQuantity'),
            'lotB_after' => (float) DB::table('purchase_details')->where('id', $lotB)->value('conversionQuantity'),
        ]);

        $this->assertCount(2, $events);
        $this->assertSame($lotA, (int) $events[0]->purchase_detail_id, 'oldest lot first');
        $this->assertSame(-30.0, (float) $events[0]->quantity_delta);
        $this->assertSame($lotB, (int) $events[1]->purchase_detail_id);
        $this->assertSame(-20.0, (float) $events[1]->quantity_delta);
        $this->assertSame(1, $events->pluck('correlation_id')->unique()->count());

        $this->assertInvariant($stockId, $product->id, 'fifo_after_sale');

        // Cancel: each lot must get back exactly what it lent.
        $this->deleteJson("/api/v1/sales/{$saleId}")->assertSuccessful();

        $this->record('fifo/after_cancel', [
            'lotA' => (float) DB::table('purchase_details')->where('id', $lotA)->value('conversionQuantity'),
            'lotB' => (float) DB::table('purchase_details')->where('id', $lotB)->value('conversionQuantity'),
        ]);

        $this->assertSame(30.0, (float) DB::table('purchase_details')->where('id', $lotA)->value('conversionQuantity'));
        $this->assertSame(40.0, (float) DB::table('purchase_details')->where('id', $lotB)->value('conversionQuantity'));

        $this->assertInvariant($stockId, $product->id, 'fifo_after_cancel');
    }

    public function test_mixed_conversion_values_still_satisfy_the_invariant(): void
    {
        // A plain SUM would be wrong here: one batch holds strips of 10, the
        // other strips of 5, and the product tally is in whole strips.
        $product = $this->product('MIXED', 10);

        $this->buy($product, [
            'batch_no' => 'E2E-CV10', 'quantities' => 10,
            'conversion_quantities' => 100, 'conversion_value' => 10,
        ])->assertStatus(200);

        $this->buy($product, [
            'batch_no' => 'E2E-CV5', 'quantities' => 10,
            'conversion_quantities' => 50, 'conversion_value' => 5,
        ])->assertStatus(200);

        $batches = DB::table('stocks')->where('product_id', $product->id)->pluck('id')->all();
        $this->assertCount(2, $batches);

        $expected = 0.0;
        foreach ($batches as $sid) {
            $expected += $this->authoritativeUpper((int) $sid);
        }

        $this->record('mixed_conversion', [
            'batches' => $batches,
            'per_batch_upper' => array_map(fn ($s) => $this->authoritativeUpper((int) $s), $batches),
            'expected_product_tally' => round($expected, 4),
            'actual_product_tally' => $this->productTally($product->id),
        ]);

        $this->assertSame(round($expected, 4), round($this->productTally($product->id), 4));
    }
}

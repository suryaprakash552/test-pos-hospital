<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Services\BatchConsumption;
use App\Services\InventoryChangeService;
use App\Services\Inventory\MovementContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which lot a sale took its stock from, and giving it back to that same lot.
 *
 * Reversal used to return the whole quantity to the newest lot. A sale drawing
 * 30 from one lot and 20 from another handed all 50 back to the second: the
 * batch total came out right, so nothing looked wrong on any screen, while the
 * lots underneath it misstated both what remained and what it had cost.
 *
 * The events written per lot are the allocation record - there is no separate
 * allocation table - so these tests read them back the way the reversal does.
 */
class BatchConsumptionLineageTest extends TestCase
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
            'role' => 'owner',
        ]);
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->product = Product::create([
            'productName' => 'Lineage Item',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'LIN-1',
            'productStock' => 0,
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'batch_no' => 'LOT-BATCH',
            'productStock' => 0,
            'conversionValue' => 1,
        ]);

        $this->actingAs($this->user);
    }

    /** Adds one purchase lot holding [quantity] sellable units. */
    private function lot(float $quantity): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id' => $this->business->id,
            'invoiceNumber' => 'P-'.uniqid(),
            'purchaseDate' => '2026-07-01',
            'totalAmount' => 0,
            'paidAmount' => 0,
            'dueAmount' => 0,
        ]);

        return (int) DB::table('purchase_details')->insertGetId([
            'purchase_id' => $purchaseId,
            'product_id' => $this->product->id,
            'stock_id' => $this->stock->id,
            'quantities' => $quantity,
            'conversionQuantity' => $quantity,
            'conversionValue' => 1,
            'productPurchasePrice' => 10,
            'conversionPurchasePrice' => 10,
            'productSalePrice' => 20,
            'productWholeSalePrice' => 20,
            'productDealerPrice' => 20,
            'purchaseUpperLimit' => '',
            'purchaseLowerLimit' => '',
            'totalPrice' => 0,
            'totalPurchasePrice' => 0,
        ]);
    }

    private function sale(): Sale
    {
        return Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'S-'.uniqid(),
            'saleDate' => '2026-08-01',
            'totalAmount' => 0,
            'paidAmount' => 0,
            'dueAmount' => 0,
        ]);
    }

    private function held(int $lotId): float
    {
        return (float) DB::table('purchase_details')->where('id', $lotId)->value('conversionQuantity');
    }

    /** @return array<int,float> lot id => signed delta, in write order */
    private function events(Sale $sale, string $type): array
    {
        return InventoryChangeLog::query()
            ->where('source_type', Sale::class)
            ->where('source_id', $sale->id)
            ->where('event_type', $type)
            ->orderBy('id')
            ->pluck('quantity_delta', 'purchase_detail_id')
            ->map(fn ($d) => (float) $d)
            ->all();
    }

    public function test_a_single_lot_sale_records_one_event_against_that_lot(): void
    {
        $lot = $this->lot(50);
        $sale = $this->sale();
        $context = MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id);

        $this->assertTrue(BatchConsumption::consume($this->stock->id, 20, $context));

        $rows = InventoryChangeLog::where('source_id', $sale->id)->get();

        $this->assertCount(1, $rows);
        $this->assertSame($lot, (int) $rows[0]->purchase_detail_id);
        $this->assertSame(-20.0, (float) $rows[0]->quantity_delta);
        $this->assertSame(InventoryChangeService::SALE_OUT, $rows[0]->event_type);
        $this->assertSame(Sale::class, $rows[0]->source_type);
        $this->assertSame('sale-'.$sale->id, $rows[0]->correlation_id);
        $this->assertSame($this->business->id, (int) $rows[0]->business_id);
        $this->assertSame($this->product->id, (int) $rows[0]->product_id);
        $this->assertSame($this->stock->id, (int) $rows[0]->stock_id);
        $this->assertSame($this->user->id, (int) $rows[0]->user_id);
    }

    public function test_a_multi_lot_sale_records_each_lot_separately(): void
    {
        $lotA = $this->lot(30);
        $lotB = $this->lot(40);
        $sale = $this->sale();

        $this->assertTrue(BatchConsumption::consume(
            $this->stock->id,
            50,
            MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id),
        ));

        $events = $this->events($sale, InventoryChangeService::SALE_OUT);

        // Oldest lot first: A is emptied, the remainder comes from B.
        $this->assertCount(2, $events);
        $this->assertSame(-30.0, $events[$lotA]);
        $this->assertSame(-20.0, $events[$lotB]);
        $this->assertSame(-50.0, array_sum($events));

        $this->assertSame(0.0, $this->held($lotA));
        $this->assertSame(20.0, $this->held($lotB));
    }

    public function test_reversal_returns_each_lot_exactly_what_it_lent(): void
    {
        $lotA = $this->lot(30);
        $lotB = $this->lot(40);
        $sale = $this->sale();
        $context = MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id);

        BatchConsumption::consume($this->stock->id, 50, $context);

        $restored = BatchConsumption::restoreAllocation($this->stock->id, $context);

        $this->assertSame(50.0, $restored);

        // The defect: B would have come back holding 60 and A nothing.
        $this->assertSame(30.0, $this->held($lotA), 'lot A must get its 30 back');
        $this->assertSame(40.0, $this->held($lotB), 'lot B must get only its 20 back');

        $back = $this->events($sale, InventoryChangeService::SALE_REVERSAL_IN);
        $this->assertSame(30.0, $back[$lotA]);
        $this->assertSame(20.0, $back[$lotB]);
    }

    public function test_a_reversal_cannot_be_applied_twice(): void
    {
        $lotA = $this->lot(30);
        $lotB = $this->lot(40);
        $sale = $this->sale();
        $context = MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id);

        BatchConsumption::consume($this->stock->id, 50, $context);
        BatchConsumption::restoreAllocation($this->stock->id, $context);

        // Nothing is outstanding any more, so a second attempt is a no-op
        // rather than a second helping of stock.
        $this->assertSame(0.0, BatchConsumption::restoreAllocation($this->stock->id, $context));

        $this->assertSame(30.0, $this->held($lotA));
        $this->assertSame(40.0, $this->held($lotB));
    }

    public function test_the_whole_operation_shares_one_correlation_id(): void
    {
        $this->lot(30);
        $this->lot(40);
        $sale = $this->sale();
        $context = MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id);

        BatchConsumption::consume($this->stock->id, 50, $context);

        $correlations = InventoryChangeLog::where('source_id', $sale->id)
            ->pluck('correlation_id')
            ->unique();

        $this->assertCount(1, $correlations);
        $this->assertSame('sale-'.$sale->id, $correlations->first());
    }

    public function test_events_are_written_in_consumption_order(): void
    {
        $lotA = $this->lot(30);
        $lotB = $this->lot(40);
        $sale = $this->sale();

        BatchConsumption::consume(
            $this->stock->id,
            50,
            MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id),
        );

        $order = InventoryChangeLog::where('source_id', $sale->id)
            ->orderBy('id')
            ->pluck('purchase_detail_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $this->assertSame([$lotA, $lotB], $order, 'oldest lot must be recorded first');
    }

    public function test_derived_tallies_rebuild_from_the_lots_after_both_directions(): void
    {
        $this->lot(30);
        $this->lot(40);
        $sale = $this->sale();
        $context = MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id);

        $service = app(InventoryChangeService::class);

        BatchConsumption::consume($this->stock->id, 50, $context);
        $service->synchronizeDerivedTallies($this->stock->fresh());

        $this->assertSame(20.0, $this->authoritative());
        $this->assertSame(20.0, $this->batchTally());
        $this->assertSame(20.0, $this->productTally());

        BatchConsumption::restoreAllocation($this->stock->id, $context);
        $service->synchronizeDerivedTallies($this->stock->fresh());

        $this->assertSame(70.0, $this->authoritative());
        $this->assertSame(70.0, $this->batchTally());
        $this->assertSame(70.0, $this->productTally());
    }

    public function test_a_failed_consumption_moves_nothing_and_records_nothing(): void
    {
        $lot = $this->lot(10);
        $sale = $this->sale();

        $this->assertFalse(BatchConsumption::consume(
            $this->stock->id,
            999,
            MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id),
        ));

        $this->assertSame(10.0, $this->held($lot));
        $this->assertSame(0, InventoryChangeLog::where('source_id', $sale->id)->count());
    }

    public function test_a_rolled_back_transaction_leaves_no_trace(): void
    {
        $lot = $this->lot(50);
        $sale = $this->sale();

        try {
            DB::transaction(function () use ($sale) {
                BatchConsumption::consume(
                    $this->stock->id,
                    20,
                    MovementContext::forSource($sale, 'sale', 'sale-'.$sale->id),
                );

                throw new \RuntimeException('forced failure after the stock moved');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(50.0, $this->held($lot), 'the lot must be untouched');
        $this->assertSame(
            0,
            InventoryChangeLog::where('source_id', $sale->id)->count(),
            'an event must not survive the rollback that undid its movement'
        );
    }


    /**
     * Two sales cannot both spend the same lot balance.
     *
     * `consume` selects the lots `lockForUpdate` and checks the batch total
     * inside that lock, so a second transaction reading the same rows waits
     * rather than deciding against a balance that is already spoken for. This
     * asserts the lock is still requested - removing it would leave the check
     * and the deduction racing, and the batch could go negative.
     */
    public function test_consumption_locks_the_lots_it_reads(): void
    {
        $source = file_get_contents(base_path('app/Services/BatchConsumption.php'));

        $consume = substr($source, strpos($source, 'public static function consume('));
        $consume = substr($consume, 0, strpos($consume, 'public static function restoreAllocation'));

        $this->assertStringContainsString('lockForUpdate()', $consume);
        $this->assertStringContainsString("orderBy('id')", $consume, 'FIFO order must survive');

        // The total is checked before anything is deducted, so a shortfall
        // cannot leave the batch partially drawn down.
        $this->assertLessThan(
            strpos($consume, 'update('),
            strpos($consume, "sum('conversionQuantity') < \$quantity"),
            'the availability check must precede the first deduction'
        );
    }

    /** Restoration locks each lot it credits, so it cannot race a concurrent sale. */
    public function test_restoration_locks_each_lot_it_credits(): void
    {
        $source = file_get_contents(base_path('app/Services/BatchConsumption.php'));

        $restore = substr($source, strpos($source, 'public static function restoreAllocation('));
        $restore = substr($restore, 0, strpos($restore, 'public static function restore('));

        $this->assertStringContainsString('lockForUpdate()', $restore);

        // No fresh FIFO pass on the way back: re-allocating is what put the
        // whole quantity onto one lot.
        $this->assertStringNotContainsString("where('conversionQuantity', '>', 0)", $restore);
    }

    private function authoritative(): float
    {
        return (float) DB::table('purchase_details')
            ->where('stock_id', $this->stock->id)
            ->sum('conversionQuantity');
    }

    private function batchTally(): float
    {
        return (float) DB::table('stocks')->where('id', $this->stock->id)->value('productStock');
    }

    private function productTally(): float
    {
        return (float) DB::table('products')->where('id', $this->product->id)->value('productStock');
    }
}

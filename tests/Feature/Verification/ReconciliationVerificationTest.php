<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reconciliation repairs the redundant tallies and nothing else.
 *
 * The authoritative lot balance is never a thing to be repaired: if it is
 * wrong, the source documents are wrong, and rewriting it would hide that.
 * These prove the command keeps to that line, on an isolated test database.
 */
class ReconciliationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id, 'role' => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id, 'name' => 'FINAL_E2E Rec', 'type' => 'Supplier', 'due' => 0,
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->actingAs($this->user);
    }

    private function record(string $k, array $d): void
    {
        fwrite(STDERR, "\n[EVIDENCE] {$k}: ".json_encode($d)."\n");
    }

    private function stockedProduct(): array
    {
        $product = Product::create([
            'productName' => 'FINAL_E2E REC',
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'productCode' => 'FE2E-REC', 'productStock' => 0,
        ]);

        $this->postJson('/api/v1/purchase', [
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $product->id, 'batch_no' => 'FE2E-REC-B',
                'quantities' => 10, 'productPurchasePrice' => 50, 'productSalePrice' => 80,
                'conversion_purchase_price' => 50, 'conversion_quantities' => 10,
                'conversion_value' => 1, 'conversion_price' => 80,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 500, 'total_price' => 800,
            ]],
        ])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        return [$product, $stockId];
    }

    private function state(int $productId, int $stockId): array
    {
        return [
            'lots' => (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity'),
            'stock_tally' => (float) DB::table('stocks')->where('id', $stockId)->value('productStock'),
            'product_tally' => (float) DB::table('products')->where('id', $productId)->value('productStock'),
        ];
    }

    public function test_dry_run_reports_drift_and_changes_nothing(): void
    {
        [$product, $stockId] = $this->stockedProduct();

        // Drift only the redundant tallies. The lots stay correct.
        DB::table('stocks')->where('id', $stockId)->update(['productStock' => 999]);
        DB::table('products')->where('id', $product->id)->update(['productStock' => 888]);

        $before = $this->state($product->id, $stockId);
        $eventsBefore = InventoryChangeLog::count();

        $this->artisan('inventory:reconcile --dry-run')->assertSuccessful();

        $after = $this->state($product->id, $stockId);

        $this->record('C/dry_run', [
            'before' => $before, 'after' => $after,
            'events_before' => $eventsBefore, 'events_after' => InventoryChangeLog::count(),
        ]);

        $this->assertSame($before, $after, 'a dry run must not write');
        $this->assertSame($eventsBefore, InventoryChangeLog::count(), 'a dry run must not record events');
    }

    public function test_the_default_run_also_changes_nothing(): void
    {
        [$product, $stockId] = $this->stockedProduct();
        DB::table('stocks')->where('id', $stockId)->update(['productStock' => 555]);

        $before = $this->state($product->id, $stockId);
        $this->artisan('inventory:reconcile')->assertSuccessful();

        $this->record('C/default_run', ['before' => $before, 'after' => $this->state($product->id, $stockId)]);

        $this->assertSame($before, $this->state($product->id, $stockId), 'read-only by default');
    }

    public function test_apply_repairs_only_the_derived_tallies(): void
    {
        [$product, $stockId] = $this->stockedProduct();

        $lotsBefore = (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity');

        DB::table('stocks')->where('id', $stockId)->update(['productStock' => 999]);
        DB::table('products')->where('id', $product->id)->update(['productStock' => 888]);

        $this->artisan('inventory:reconcile --apply --confirm')->assertSuccessful();

        $after = $this->state($product->id, $stockId);

        $this->record('C/apply', [
            'lots_before' => $lotsBefore,
            'after' => $after,
            'reconcile_events' => InventoryChangeLog::where('event_type', 'derived_tally_reconciled')->count(),
        ]);

        // The lots are untouched, and both tallies are rebuilt from them.
        $this->assertSame($lotsBefore, $after['lots'], 'conversionQuantity must never be rewritten');
        $this->assertSame(10.0, $after['stock_tally']);
        $this->assertSame(10.0, $after['product_tally']);

        $this->assertGreaterThan(
            0,
            InventoryChangeLog::where('event_type', 'derived_tally_reconciled')->count(),
            'a correction must leave an immutable record of itself'
        );
    }

    public function test_a_second_apply_is_idempotent(): void
    {
        [$product, $stockId] = $this->stockedProduct();
        DB::table('stocks')->where('id', $stockId)->update(['productStock' => 999]);

        $this->artisan('inventory:reconcile --apply --confirm')->assertSuccessful();
        $first = $this->state($product->id, $stockId);
        $eventsAfterFirst = InventoryChangeLog::where('event_type', 'derived_tally_reconciled')->count();

        $this->artisan('inventory:reconcile --apply --confirm')->assertSuccessful();
        $second = $this->state($product->id, $stockId);
        $eventsAfterSecond = InventoryChangeLog::where('event_type', 'derived_tally_reconciled')->count();

        $this->record('C/idempotency', [
            'after_first' => $first, 'after_second' => $second,
            'events_first' => $eventsAfterFirst, 'events_second' => $eventsAfterSecond,
        ]);

        $this->assertSame($first, $second, 'nothing left to correct means nothing changes');
        $this->assertSame($eventsAfterFirst, $eventsAfterSecond, 'a clean run must not invent a correction event');
    }
}

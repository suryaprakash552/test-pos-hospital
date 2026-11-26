<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\InventoryChangeLog;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockDisposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Taking expired stock off the shelf, and proving it.
 *
 * The rule that matters most here is *where the quantity comes from*. Disposal
 * drains the purchase lots — the ledger a sale consumes and `available_quantity`
 * is summed from — and then **sets** `stocks.productStock` to what remains
 * rather than decrementing it. On the live data 57 of 158 expired batches hold a
 * negative `productStock` and 131 disagree with their own lots, so decrementing
 * would push the first group further wrong and leave the second untouched.
 */
class StockDisposalTest extends TestCase
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

        $this->actingAs($this->user);
    }

    /** A batch with [$quantity] lower units, expiring on [$expiry]. */
    private function batch(string $expiry, float $quantity = 100, float $cost = 5, float $conversion = 10, float $productStock = 10): Stock
    {
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'conversionValue' => $conversion,
        ]);

        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B-'.uniqid(),
            'expire_date' => $expiry,
            'conversionValue' => $conversion,
            'productStock' => $productStock,
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'P-'.uniqid(),
            'purchaseDate' => now()->toDateString(),
            'totalAmount' => 0,
        ]);

        DB::table('purchase_details')->insert([
            'purchase_id' => $purchaseId,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => $quantity / max($conversion, 1),
            'conversionQuantity' => $quantity,
            'conversionValue' => $conversion,
            'conversionPurchasePrice' => $cost,
            'productPurchasePrice' => $cost,
            'purchaseUpperLimit' => 0,
            'purchaseLowerLimit' => 0,
        ]);

        return $stock;
    }

    private function lotQuantity(int $stockId): float
    {
        return (float) DB::table('purchase_details')
            ->where('stock_id', $stockId)
            ->sum('conversionQuantity');
    }

    // ── The queue ──────────────────────────────────────────────────────────

    public function test_the_queue_lists_expired_batches_that_still_hold_stock(): void
    {
        $expired = $this->batch(now()->subMonth()->toDateString());
        $this->batch(now()->addMonth()->toDateString());

        $ids = collect(
            $this->getJson('/api/v1/expired-stock/pending')->assertOk()->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($expired->id));
        $this->assertCount(1, $ids);
    }

    /** An expired batch already emptied is not waiting for anything. */
    public function test_an_emptied_expired_batch_leaves_the_queue(): void
    {
        $stock = $this->batch(now()->subMonth()->toDateString(), quantity: 0);

        $ids = collect(
            $this->getJson('/api/v1/expired-stock/pending')->assertOk()->json('data')
        )->pluck('id');

        $this->assertFalse($ids->contains($stock->id));
    }

    /** "Select all filtered" needs every id in the filtered set, not one page. */
    public function test_the_queue_returns_every_matching_id_for_select_all(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->batch(now()->subMonth()->toDateString());
        }

        $response = $this->getJson('/api/v1/expired-stock/pending?per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertCount(3, $response->json('totals.stock_ids'));
    }

    public function test_the_queue_filters_by_expiry_range(): void
    {
        $old = $this->batch(now()->subYear()->toDateString());
        $recent = $this->batch(now()->subDays(5)->toDateString());

        $ids = collect(
            $this->getJson('/api/v1/expired-stock/pending?expired_from='
                .now()->subMonth()->toDateString())->assertOk()->json('data')
        )->pluck('id');

        $this->assertTrue($ids->contains($recent->id));
        $this->assertFalse($ids->contains($old->id));
    }

    // ── Disposing ──────────────────────────────────────────────────────────

    public function test_disposal_empties_the_purchase_lots(): void
    {
        $stock = $this->batch(now()->subMonth()->toDateString(), quantity: 100);

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])
            ->assertOk();

        $this->assertSame(0.0, $this->lotQuantity($stock->id));
        $this->assertDatabaseHas('inventory_change_logs', [
            'event_type' => 'disposal_out',
            'stock_id' => $stock->id,
            'quantity_delta' => -100,
        ]);
    }

    /**
     * `productStock` is *set* from what remains, not decremented.
     *
     * This batch starts with a nonsense figure — the state 131 of 158 live
     * expired batches are in. Disposal must leave it at zero, not at
     * `nonsense - disposed`.
     */
    public function test_disposal_repairs_product_stock_rather_than_decrementing_it(): void
    {
        $stock = $this->batch(
            now()->subMonth()->toDateString(),
            quantity: 100,
            conversion: 10,
            productStock: -37,
        );

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])
            ->assertOk();

        $this->assertSame(0.0, (float) $stock->fresh()->productStock);
    }

    public function test_the_disposal_records_quantity_and_value_at_cost(): void
    {
        $stock = $this->batch(now()->subMonth()->toDateString(), quantity: 100, cost: 5);

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])
            ->assertOk();

        $disposal = StockDisposal::latest('id')->first();

        $this->assertEqualsWithDelta(100.0, (float) $disposal->total_quantity, 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $disposal->total_value, 0.01);
    }

    /** The line keeps its own copy of what was destroyed. */
    public function test_the_line_snapshots_the_product_and_batch(): void
    {
        $stock = $this->batch(now()->subMonth()->toDateString());
        $originalBatch = $stock->batch_no;

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])
            ->assertOk();

        // Rename the product and the batch afterwards.
        $stock->product->forceFill(['productName' => 'Renamed Later'])->save();
        $stock->forceFill(['batch_no' => 'CHANGED'])->save();

        $line = StockDisposal::latest('id')->first()->lines->first();

        $this->assertSame($originalBatch, $line->batch_no);
        $this->assertNotSame('Renamed Later', $line->product_name);
    }

    public function test_many_batches_are_disposed_together(): void
    {
        $ids = collect(range(1, 5))
            ->map(fn () => $this->batch(now()->subMonth()->toDateString())->id)
            ->all();

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => $ids])->assertOk();

        foreach ($ids as $id) {
            $this->assertSame(0.0, $this->lotQuantity($id));
        }
    }

    // ── Guards ─────────────────────────────────────────────────────────────

    /** This must never become a back door for adjusting live inventory. */
    public function test_a_batch_that_has_not_expired_is_refused(): void
    {
        $stock = $this->batch(now()->addMonth()->toDateString(), quantity: 100);

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_expired');

        $this->assertSame(100.0, $this->lotQuantity($stock->id));
    }

    /** All of them, or none — a partial disposal leaves stock unaccounted for. */
    public function test_one_bad_batch_disposes_none_of_them(): void
    {
        $expired = $this->batch(now()->subMonth()->toDateString(), quantity: 100);
        $live = $this->batch(now()->addMonth()->toDateString(), quantity: 100);

        $this->postJson('/api/v1/stock-disposals', [
            'stock_ids' => [$expired->id, $live->id],
        ])->assertStatus(422);

        $this->assertSame(100.0, $this->lotQuantity($expired->id), 'the whole batch rolls back');
        $this->assertSame(0, StockDisposal::count());
    }

    public function test_another_businesss_batch_is_refused(): void
    {
        $otherBusiness = Business::factory()->create();
        $product = Product::factory()->create(['business_id' => $otherBusiness->id]);
        $stock = Stock::factory()->create([
            'business_id' => $otherBusiness->id,
            'product_id' => $product->id,
            'expire_date' => now()->subMonth()->toDateString(),
        ]);

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])
            ->assertStatus(404);
    }

    public function test_an_empty_selection_is_refused(): void
    {
        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => []])
            ->assertStatus(422);
    }

    // ── The register ───────────────────────────────────────────────────────

    public function test_the_register_lists_what_was_disposed(): void
    {
        $stock = $this->batch(now()->subMonth()->toDateString(), quantity: 100, cost: 5);
        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])->assertOk();

        $response = $this->getJson('/api/v1/stock-disposals')->assertOk();

        $this->assertSame(1, $response->json('totals.disposals'));
        $this->assertEqualsWithDelta(100.0, (float) $response->json('totals.quantity'), 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $response->json('totals.value'), 0.01);
    }

    public function test_the_register_filters_by_product(): void
    {
        $wanted = $this->batch(now()->subMonth()->toDateString());
        $other = $this->batch(now()->subMonth()->toDateString());

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$wanted->id]])->assertOk();
        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$other->id]])->assertOk();

        $found = $this->getJson('/api/v1/stock-disposals?product_id='.$wanted->product_id)
            ->assertOk()->json('data');

        $this->assertCount(1, $found);
    }

    public function test_the_register_filters_by_disposal_date(): void
    {
        $stock = $this->batch(now()->subMonth()->toDateString());
        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])->assertOk();

        $this->assertCount(1, $this->getJson(
            '/api/v1/stock-disposals?from_date='.today()->toDateString()
            .'&to_date='.today()->toDateString()
        )->assertOk()->json('data'));

        $this->assertCount(0, $this->getJson(
            '/api/v1/stock-disposals?from_date='.today()->addDay()->toDateString()
        )->assertOk()->json('data'));
    }

    /** A disposed batch is gone from the queue, and from sellable stock. */
    public function test_a_disposed_batch_leaves_the_queue(): void
    {
        $stock = $this->batch(now()->subMonth()->toDateString());

        $this->postJson('/api/v1/stock-disposals', ['stock_ids' => [$stock->id]])->assertOk();

        $ids = collect(
            $this->getJson('/api/v1/expired-stock/pending')->assertOk()->json('data')
        )->pluck('id');

        $this->assertFalse($ids->contains($stock->id));
    }
}

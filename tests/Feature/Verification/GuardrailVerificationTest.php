<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The refusals, verified by what the database looks like afterwards.
 *
 * A guard that returns the right status code while still moving stock is worse
 * than no guard, so each case reads the tables back rather than trusting the
 * response.
 */
class GuardrailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $mine;
    private Business $theirs;
    private User $me;
    private Party $supplier;
    private Category $category;

    private static array $evidence = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = Business::factory()->create();
        $this->theirs = Business::factory()->create();

        $this->me = User::factory()->create([
            'business_id' => $this->mine->id, 'role' => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->mine->id, 'name' => 'E2E_VERIFY Sup', 'type' => 'Supplier', 'due' => 0,
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->mine->id]);

        $this->actingAs($this->me);
    }

    private function record(string $k, array $d): void
    {
        self::$evidence[$k] = $d;
        fwrite(STDERR, "\n[EVIDENCE] {$k}: ".json_encode($d)."\n");
    }

    private function product(string $code, ?Business $b = null): Product
    {
        $b ??= $this->mine;

        return Product::create([
            'productName' => 'E2E_VERIFY '.$code,
            'business_id' => $b->id,
            'category_id' => $this->category->id,
            'productCode' => $code,
            'productStock' => 0,
        ]);
    }

    private function buy(Product $p, array $o = [])
    {
        return $this->postJson('/api/v1/purchase', [
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [array_merge([
                'product_id' => $p->id, 'batch_no' => 'E2E-G1', 'quantities' => 10,
                'productPurchasePrice' => 50, 'productSalePrice' => 80,
                'conversion_purchase_price' => 50, 'conversion_quantities' => 10,
                'conversion_value' => 1, 'conversion_price' => 80,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 500, 'total_price' => 800,
            ], $o)],
        ]);
    }

    /** P4 Scenario B, proved by the tables rather than the status code. */
    public function test_p4_a_protected_batch_rename_moves_nothing(): void
    {
        $product = $this->product('P4PROT');
        $this->buy($product)->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        // Movement out of the batch: the strongest lineage the architecture has.
        InventoryChangeLog::create([
            'business_id' => $this->mine->id, 'event_type' => 'sale_out',
            'source_type' => 'App\\Models\\Sale', 'source_id' => 999,
            'stock_id' => $stockId, 'product_id' => $product->id, 'quantity_delta' => -1,
        ]);

        $before = [
            'batch_no' => DB::table('stocks')->where('id', $stockId)->value('batch_no'),
            'lots' => (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity'),
            'stock_tally' => (float) DB::table('stocks')->where('id', $stockId)->value('productStock'),
            'product_tally' => (float) DB::table('products')->where('id', $product->id)->value('productStock'),
            'detail_rows' => DB::table('purchase_details')->where('stock_id', $stockId)->count(),
            'events' => InventoryChangeLog::count(),
            'stock_rows' => DB::table('stocks')->where('product_id', $product->id)->count(),
        ];

        $response = $this->buy($product, ['batch_no' => 'RENAMED', 'stock_id' => $stockId]);

        $after = [
            'batch_no' => DB::table('stocks')->where('id', $stockId)->value('batch_no'),
            'lots' => (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity'),
            'stock_tally' => (float) DB::table('stocks')->where('id', $stockId)->value('productStock'),
            'product_tally' => (float) DB::table('products')->where('id', $product->id)->value('productStock'),
            'detail_rows' => DB::table('purchase_details')->where('stock_id', $stockId)->count(),
            'events' => InventoryChangeLog::count(),
            'stock_rows' => DB::table('stocks')->where('product_id', $product->id)->count(),
        ];

        $this->record('p4/protected', ['status' => $response->status(), 'before' => $before, 'after' => $after]);

        $response->assertStatus(409)->assertJsonPath('code', 'BATCH_IDENTITY_PROTECTED');
        $this->assertSame($before, $after, 'a refused rename must leave the database exactly as it was');
    }

    /** P4 Scenario A: a clean rename corrects in place, without a second batch. */
    public function test_p4_a_clean_rename_corrects_in_place(): void
    {
        $product = $this->product('P4CLEAN');
        $this->buy($product, ['batch_no' => 'TYPO'])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');
        $lotsBefore = (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity');

        $this->buy($product, ['batch_no' => 'FIXED', 'stock_id' => $stockId])->assertStatus(200);

        $after = [
            'batch_no' => DB::table('stocks')->where('id', $stockId)->value('batch_no'),
            'stock_rows' => DB::table('stocks')->where('product_id', $product->id)->count(),
            'lots_before' => $lotsBefore,
            'lots_after' => (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity'),
        ];

        $this->record('p4/clean_rename', $after);

        $this->assertSame('FIXED', $after['batch_no']);
        $this->assertSame(1, $after['stock_rows'], 'no duplicate batch may be created');
    }

    /** A return against a batch with no lot must refuse and change nothing. */
    public function test_a_lotless_sale_return_is_refused_without_touching_inventory(): void
    {
        $product = $this->product('LOTLESS');

        // A batch with a tally and no purchase lot: the legacy phantom shape.
        $stock = Stock::create([
            'business_id' => $this->mine->id, 'product_id' => $product->id,
            'batch_no' => 'PHANTOM', 'productStock' => 5, 'conversionValue' => 1,
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'business_id' => $this->mine->id, 'invoiceNumber' => 'E2E-S1',
            'saleDate' => now()->toDateString(), 'totalAmount' => 80,
        ]);
        $detailId = DB::table('sale_details')->insertGetId([
            'sale_id' => $saleId, 'product_id' => $product->id, 'stock_id' => $stock->id,
            'quantities' => 1, 'price' => 80, 'lossProfit' => 0, 'productPurchasePrice' => 50,
        ]);

        $before = [
            'stock_tally' => (float) DB::table('stocks')->where('id', $stock->id)->value('productStock'),
            'product_tally' => (float) DB::table('products')->where('id', $product->id)->value('productStock'),
            'lots' => (float) DB::table('purchase_details')->where('stock_id', $stock->id)->sum('conversionQuantity'),
            'returns' => DB::table('sale_returns')->count(),
            'events' => InventoryChangeLog::count(),
        ];

        $response = $this->postJson('/api/v1/sales-return', [
            'sale_id' => $saleId,
            'return_date' => now()->toDateString(),
            'sale_detail_id' => [$detailId],
            'return_qty' => [1],
            'return_amount' => [80],
        ]);

        $after = [
            'stock_tally' => (float) DB::table('stocks')->where('id', $stock->id)->value('productStock'),
            'product_tally' => (float) DB::table('products')->where('id', $product->id)->value('productStock'),
            'lots' => (float) DB::table('purchase_details')->where('stock_id', $stock->id)->sum('conversionQuantity'),
            'returns' => DB::table('sale_returns')->count(),
            'events' => InventoryChangeLog::count(),
        ];

        $this->record('lotless_return', [
            'status' => $response->status(),
            'reason' => $response->json('reason') ?? $response->json('code'),
            'before' => $before,
            'after' => $after,
        ]);

        $this->assertSame(409, $response->status());
        $this->assertSame($before, $after, 'a refused return must change nothing');
    }

    /** Tenant isolation on the paths this work touched. */
    public function test_cross_tenant_ids_are_not_reachable_on_the_changed_paths(): void
    {
        $theirProduct = $this->product('THEIRS', $this->theirs);
        $theirStock = Stock::create([
            'business_id' => $this->theirs->id, 'product_id' => $theirProduct->id,
            'batch_no' => 'THEIR-B', 'productStock' => 9, 'conversionValue' => 1,
        ]);

        $before = [
            'their_batch' => DB::table('stocks')->where('id', $theirStock->id)->value('batch_no'),
            'their_tally' => (float) DB::table('stocks')->where('id', $theirStock->id)->value('productStock'),
        ];

        // Purchase naming another tenant's stock id.
        $purchase = $this->buy($theirProduct, ['batch_no' => 'HIJACK', 'stock_id' => $theirStock->id]);

        // Product delete on another tenant's product.
        $delete = $this->deleteJson("/api/v1/products/{$theirProduct->id}");

        $after = [
            'their_batch' => DB::table('stocks')->where('id', $theirStock->id)->value('batch_no'),
            'their_tally' => (float) DB::table('stocks')->where('id', $theirStock->id)->value('productStock'),
            'product_exists' => DB::table('products')->where('id', $theirProduct->id)->exists(),
        ];

        $this->record('tenant_isolation', [
            'purchase_status' => $purchase->status(),
            'delete_status' => $delete->status(),
            'before' => $before,
            'after' => $after,
        ]);

        $this->assertNotSame(200, $purchase->status(), 'another tenant\'s batch must not be writable');
        $this->assertSame($before['their_batch'], $after['their_batch']);
        $this->assertSame($before['their_tally'], $after['their_tally']);
        $this->assertTrue($after['product_exists'], 'another tenant\'s product must not be deletable');
        $this->assertSame(403, $delete->status());
    }
}

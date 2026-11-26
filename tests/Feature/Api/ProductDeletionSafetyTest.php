<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\InventoryChangeLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Services\ProductTransactionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A product carrying inventory history must survive a delete request.
 *
 * The database refuses some of this on its own - `purchase_details` and
 * `sale_details` are RESTRICT - but it refuses by throwing, mid-request, after
 * earlier products in a bulk call had already gone. And it does not refuse the
 * dangerous case at all: `stocks.product_id` CASCADES, so deleting a product
 * with a batch and no purchase behind it silently takes the batch too. Those are
 * the legacy rows the phantom-stock survey found, and they are evidence.
 *
 * `inventory_change_logs` has no foreign key whatsoever, so nothing but the
 * application can stop a delete from orphaning immutable history.
 */
class ProductDeletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Category $category;
    private User $webUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);

        // The web panel admits only `shop-owner`/`staff`; the API accepts
        // `owner`. Both doors are exercised here, so both roles are needed.
        $this->webUser = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        // The web panel refuses writes without an active plan (406), which has
        // nothing to do with deletion safety - give the shop one so the guard
        // under test is what answers.
        $this->business->update(['will_expire' => now()->addYear()]);

        DB::table('plan_subscribes')->insert([
            'business_id' => $this->business->id,
            'plan_id' => DB::table('plans')->insertGetId([
                'subscriptionName' => 'Test Plan',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        cache()->flush();

        $this->actingAs($this->user);
    }

    private function product(string $code, ?Business $business = null): Product
    {
        $business ??= $this->business;

        return Product::create([
            'productName' => 'Item '.$code,
            'business_id' => $business->id,
            'category_id' => $this->category->id,
            'productCode' => $code,
            'productStock' => 0,
        ]);
    }

    private function batch(Product $product): Stock
    {
        return Stock::create([
            'business_id' => $product->business_id,
            'product_id' => $product->id,
            'batch_no' => 'B-'.uniqid(),
            'productStock' => 5,
            'conversionValue' => 1,
        ]);
    }

    private function lot(Product $product, Stock $stock): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id' => $product->business_id,
            'invoiceNumber' => 'P-'.uniqid(),
            'purchaseDate' => '2026-07-01',
            'totalAmount' => 0, 'paidAmount' => 0, 'dueAmount' => 0,
        ]);

        return (int) DB::table('purchase_details')->insertGetId([
            'purchase_id' => $purchaseId,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 5,
            'conversionQuantity' => 5,
            'conversionValue' => 1,
            'productPurchasePrice' => 10, 'conversionPurchasePrice' => 10,
            'productSalePrice' => 20, 'productWholeSalePrice' => 20, 'productDealerPrice' => 20,
            'purchaseUpperLimit' => '', 'purchaseLowerLimit' => '',
            'totalPrice' => 0, 'totalPurchasePrice' => 0,
        ]);
    }

    private function deleteProduct(Product $product)
    {
        return $this->deleteJson("/api/v1/products/{$product->id}");
    }

    private function bulkDelete(array $ids)
    {
        return $this->actingAs($this->webUser)
            ->postJson(route('business.products.delete-all'), ['ids' => $ids]);
    }

    // ---- 1. the permitted case -------------------------------------------

    public function test_an_unused_product_is_still_deletable(): void
    {
        $product = $this->product('FREE-1');

        $this->deleteProduct($product)->assertSuccessful();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    // ---- 2-6. each blocker on its own ------------------------------------

    public function test_a_product_with_purchase_history_is_protected(): void
    {
        $product = $this->product('PUR-1');
        $this->lot($product, $this->batch($product));

        $this->deleteProduct($product)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRODUCT_HISTORY_PROTECTED');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_product_with_sale_history_is_protected(): void
    {
        $product = $this->product('SAL-1');

        $saleId = DB::table('sales')->insertGetId([
            'business_id' => $this->business->id,
            'invoiceNumber' => 'S-'.uniqid(),
            'saleDate' => '2026-08-01',
            'totalAmount' => 10,
        ]);
        DB::table('sale_details')->insert([
            'sale_id' => $saleId,
            'product_id' => $product->id,
            'quantities' => 1,
            'price' => 10,
            'lossProfit' => 0,
            'productPurchasePrice' => 0,
        ]);

        $this->deleteProduct($product)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRODUCT_HISTORY_PROTECTED');
    }

    /**
     * The cascade case. Nothing in the database stops this one.
     *
     * A batch with no purchase and no sale behind it is exactly the legacy shape
     * the phantom survey found. `stocks.product_id` cascades, so without this
     * check the delete succeeds and quietly takes the batch with it.
     */
    public function test_a_product_with_only_a_stock_batch_is_protected(): void
    {
        $product = $this->product('BAT-1');
        $stock = $this->batch($product);

        $this->deleteProduct($product)
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRODUCT_HISTORY_PROTECTED')
            ->assertJsonPath('history.batches', 1);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id]);
    }

    /** No foreign key protects this; only the application can. */
    public function test_a_product_with_only_an_inventory_event_is_protected(): void
    {
        $product = $this->product('EVT-1');

        InventoryChangeLog::create([
            'business_id' => $this->business->id,
            'event_type' => 'sale_out',
            'source_type' => Sale::class,
            'source_id' => 1,
            'product_id' => $product->id,
            'quantity_delta' => -1,
        ]);

        $this->deleteProduct($product)
            ->assertStatus(409)
            ->assertJsonPath('history.inventory_events', 1);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_product_with_an_authoritative_lot_is_protected(): void
    {
        $product = $this->product('LOT-1');
        $stock = $this->batch($product);
        $lotId = $this->lot($product, $stock);

        $this->deleteProduct($product)->assertStatus(409);

        $this->assertDatabaseHas('purchase_details', ['id' => $lotId]);
    }

    // ---- 7. bulk atomicity ------------------------------------------------

    public function test_a_bulk_delete_containing_one_protected_product_deletes_nothing(): void
    {
        $deletable = $this->product('BULK-OK');
        $protected = $this->product('BULK-NO');
        $this->batch($protected);

        $this->bulkDelete([$deletable->id, $protected->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PRODUCT_HISTORY_PROTECTED')
            ->assertJsonPath('deleted', 0);

        // The deletable one must survive: it was listed alongside a protected
        // product, and a partial delete the caller cannot see is worse than a
        // refusal they can.
        $this->assertDatabaseHas('products', ['id' => $deletable->id]);
        $this->assertDatabaseHas('products', ['id' => $protected->id]);
    }

    public function test_a_clean_bulk_delete_removes_them_all(): void
    {
        $a = $this->product('BULK-A');
        $b = $this->product('BULK-B');

        $this->bulkDelete([$a->id, $b->id])
            ->assertSuccessful()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseMissing('products', ['id' => $a->id]);
        $this->assertDatabaseMissing('products', ['id' => $b->id]);
    }

    // ---- 8-9. tenant isolation -------------------------------------------

    public function test_a_bulk_delete_cannot_reach_another_tenants_product(): void
    {
        $other = Business::factory()->create();
        $theirs = $this->product('OTHER-1', $other);
        $mine = $this->product('MINE-1');

        $this->bulkDelete([$mine->id, $theirs->id])->assertStatus(404);

        // Neither goes: theirs is out of reach, and mine must not be deleted on
        // the strength of a request that named a product it had no right to.
        $this->assertDatabaseHas('products', ['id' => $theirs->id]);
        $this->assertDatabaseHas('products', ['id' => $mine->id]);
    }

    public function test_a_single_delete_cannot_reach_another_tenants_product(): void
    {
        $other = Business::factory()->create();
        $theirs = $this->product('OTHER-2', $other);

        $this->deleteProduct($theirs)->assertStatus(403);

        $this->assertDatabaseHas('products', ['id' => $theirs->id]);
    }

    // ---- 10-12. request shapes -------------------------------------------

    public function test_duplicate_ids_are_deduplicated_rather_than_failing(): void
    {
        $product = $this->product('DUP-1');

        $this->bulkDelete([$product->id, $product->id])
            ->assertSuccessful()
            ->assertJsonPath('deleted', 1);
    }

    public function test_an_empty_id_list_is_rejected_as_validation(): void
    {
        $this->bulkDelete([])->assertStatus(422);
    }

    public function test_no_delete_path_answers_with_an_unhandled_database_error(): void
    {
        // RESTRICT would throw a QueryException mid-request. The application
        // must decide before the database has to.
        $product = $this->product('EXC-1');
        $this->lot($product, $this->batch($product));

        $this->deleteProduct($product)->assertStatus(409);
        $this->bulkDelete([$product->id])->assertStatus(409);
    }

    // ---- 14. protection changes nothing ----------------------------------

    public function test_a_refused_deletion_moves_no_inventory(): void
    {
        $product = $this->product('UNCHANGED-1');
        $stock = $this->batch($product);
        $lotId = $this->lot($product, $stock);

        DB::table('stocks')->where('id', $stock->id)->update(['productStock' => 5]);
        DB::table('products')->where('id', $product->id)->update(['productStock' => 5]);

        $before = [
            'lot' => DB::table('purchase_details')->where('id', $lotId)->value('conversionQuantity'),
            'stock' => DB::table('stocks')->where('id', $stock->id)->value('productStock'),
            'product' => DB::table('products')->where('id', $product->id)->value('productStock'),
            'events' => InventoryChangeLog::count(),
        ];

        $this->deleteProduct($product)->assertStatus(409);
        $this->bulkDelete([$product->id])->assertStatus(409);

        $this->assertSame($before['lot'], DB::table('purchase_details')->where('id', $lotId)->value('conversionQuantity'));
        $this->assertSame($before['stock'], DB::table('stocks')->where('id', $stock->id)->value('productStock'));
        $this->assertSame($before['product'], DB::table('products')->where('id', $product->id)->value('productStock'));
        $this->assertSame($before['events'], InventoryChangeLog::count());
    }

    /** The refusal says why, and does not claim an archive that does not exist. */
    public function test_the_refusal_explains_itself_without_promising_an_archive(): void
    {
        $product = $this->product('MSG-1');
        $this->batch($product);

        $response = $this->deleteProduct($product)->assertStatus(409);

        $response->assertJsonPath('action', 'archive_or_disable');
        $response->assertJsonPath('archive_available', false);
        $this->assertNotEmpty($response->json('history_text'));
    }

    /** Reads are untouched by any of this. */
    public function test_the_product_listing_still_works(): void
    {
        $product = $this->product('LIST-1');
        $this->batch($product);

        $this->getJson('/api/v1/products')->assertSuccessful();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The purchase endpoint decides the bill, not the client.
 *
 * The unit cost is an operator input - the server cannot look up what a supplier
 * charged. Everything derived from it is the server's, and these pin that.
 */
class AuthoritativePurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;

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
            'name' => 'Acme Supplies',
            'type' => 'Supplier',
            'due' => 0,
        ]);
    }

    private function product(float $rate = 0): Product
    {
        $vat = $rate > 0
            ? Vat::factory()->create(['business_id' => $this->business->id, 'rate' => $rate])
            : null;

        return Product::factory()->create([
            'business_id' => $this->business->id,
            'vat_id' => $vat?->id,
            'vat_type' => 'exclusive',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $overrides
     */
    private function purchase(array $products, array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/purchase', array_merge([
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => $products,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function line(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
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
        ], $overrides);
    }

    public function test_a_manipulated_line_total_does_not_reach_the_row(): void
    {
        $product = $this->product();

        // Claims each line totals a rupee, on 10 units at 50 each.
        $this->purchase([
            $this->line($product, ['total_purchase_price' => 1, 'total_price' => 1]),
        ], ['paidAmount' => 500])->assertStatus(200);

        $detail = PurchaseDetails::first();

        $this->assertSame(500.0, (float) $detail->totalPurchasePrice);
        $this->assertSame(800.0, (float) $detail->totalPrice);
    }

    public function test_a_purchase_creates_an_immutable_inventory_trace_and_protects_its_batch(): void
    {
        $product = $this->product();

        $this->purchase([$this->line($product)], ['paidAmount' => 500])
            ->assertSuccessful();

        $purchase = Purchase::latest('id')->firstOrFail();
        $detail = PurchaseDetails::where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertDatabaseHas('inventory_change_logs', [
            'business_id' => $this->business->id,
            'purchase_id' => $purchase->id,
            'purchase_detail_id' => $detail->id,
            'stock_id' => $detail->stock_id,
            'event_type' => 'purchase_created',
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/stocks/{$detail->stock_id}", [])
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVENTORY_HISTORY_PROTECTED')
            ->assertJsonPath('purchase_id', $purchase->id);
    }

    public function test_a_tracked_purchase_cannot_be_deleted_even_before_a_sale_exists(): void
    {
        $product = $this->product();
        $this->purchase([$this->line($product)], ['paidAmount' => 500])->assertSuccessful();
        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/purchase/{$purchase->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PURCHASE_HISTORY_PROTECTED')
            ->assertJsonPath('purchase_id', $purchase->id);

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('purchase_details', ['purchase_id' => $purchase->id]);
    }

    public function test_a_manipulated_grand_total_is_replaced(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product),
        ], ['totalAmount' => 1, 'paidAmount' => 500])->assertStatus(200);

        $this->assertSame(500.0, (float) Purchase::first()->totalAmount);
    }

    public function test_a_manipulated_tax_is_recalculated(): void
    {
        $product = $this->product(rate: 18);

        $this->purchase([
            $this->line($product),
        ], ['vat_amount' => 9999, 'paidAmount' => 590])->assertStatus(200);

        $purchase = Purchase::first();

        // 500 net, 18% on top.
        $this->assertSame(90.0, (float) $purchase->vat_amount);
        $this->assertSame(590.0, (float) $purchase->totalAmount);
    }

    public function test_the_conversion_purchase_price_is_derived_not_accepted(): void
    {
        $product = $this->product();

        // 10 upper units of 4 lower units each = 40 lower units, 500 total.
        $this->purchase([
            $this->line($product, [
                'conversion_value' => 4,
                'conversion_quantities' => 40,
                // A wildly wrong per-unit cost that would misprice the batch.
                'conversion_purchase_price' => 999,
            ]),
        ], ['paidAmount' => 500])->assertStatus(200);

        // 500 / 40 = 12.50
        $this->assertSame(12.50, (float) PurchaseDetails::first()->conversionPurchasePrice);
    }

    public function test_free_units_increase_stock_without_increasing_the_bill(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product, [
                'batch_no' => 'BONUS-10-5',
                'quantities' => 10,
                'free_quantity' => 5,
                'productPurchasePrice' => 5,
                'conversion_value' => 10,
                'conversion_quantities' => 150,
            ]),
        ], ['paidAmount' => 50])->assertOk();

        $detail = PurchaseDetails::firstOrFail();
        $stock = Stock::firstOrFail();

        $this->assertSame(50.0, (float) Purchase::firstOrFail()->totalAmount);
        $this->assertSame(10.0, (float) $detail->quantities);
        $this->assertSame(5.0, (float) $detail->free_quantity);
        $this->assertSame(150.0, (float) $detail->conversionQuantity);
        $this->assertEqualsWithDelta(0.33, (float) $detail->conversionPurchasePrice, 0.001);
        $this->assertSame(15.0, (float) $stock->productStock);
    }

    public function test_free_quantity_accepts_decimals_but_rejects_negatives(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product, [
                'free_quantity' => 1.5,
                'conversion_quantities' => 11.5,
            ]),
        ])->assertOk();

        $this->assertSame(1.5, (float) PurchaseDetails::firstOrFail()->free_quantity);

        $this->purchase([
            $this->line($product, ['free_quantity' => -0.5]),
        ])->assertStatus(422);

        $this->assertSame(1, Purchase::count());
    }

    public function test_due_is_calculated_server_side(): void
    {
        $product = $this->product();

        // Pays 200 of a 500 bill while claiming nothing is owed.
        $this->purchase([
            $this->line($product),
        ], ['paidAmount' => 200, 'dueAmount' => 0])->assertStatus(200);

        $purchase = Purchase::first();

        $this->assertSame(300.0, (float) $purchase->dueAmount);
        $this->assertSame(200.0, (float) $purchase->paidAmount);

        // And the supplier is owed that much.
        $this->assertSame(300.0, (float) $this->supplier->fresh()->due);
    }

    public function test_a_purchase_with_no_received_amount_is_saved_as_fully_due(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product),
        ], [
            'paidAmount' => 0,
            'cash_amount' => 0,
            'upi_amount' => 0,
            'card_amount' => 0,
        ])->assertOk();

        $purchase = Purchase::firstOrFail();
        $this->assertSame(0.0, (float) $purchase->paidAmount);
        $this->assertSame(500.0, (float) $purchase->dueAmount);
        $this->assertSame(500.0, (float) $this->supplier->fresh()->due);
    }

    public function test_an_excessive_discount_is_rejected(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product),
        ], ['discountAmount' => 1_000_000, 'paidAmount' => 0])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'discount_exceeds_subtotal');

        $this->assertSame(0, Purchase::count());
    }

    public function test_a_negative_amount_is_rejected(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product),
        ], ['discountAmount' => -100, 'paidAmount' => 500])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'negative_amount');

        $this->assertSame(0, Purchase::count());
    }

    public function test_another_tenants_product_cannot_be_purchased(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);

        $this->purchase([
            $this->line($foreignProduct),
        ], ['paidAmount' => 500])->assertStatus(422);

        $this->assertSame(0, Purchase::count());
    }

    public function test_stock_increases_by_the_validated_quantity(): void
    {
        $product = $this->product();

        $this->purchase([
            // Keep received upper and lower quantities aligned for this
            // particular assertion. Other tests intentionally cover a larger
            // conversion quantity as legitimate loose stock.
            $this->line($product, [
                'quantities' => 7,
                'conversion_quantities' => 7,
            ]),
        ], ['paidAmount' => 350])->assertStatus(200);

        $stock = Stock::where('product_id', $product->id)->first();

        $this->assertSame(7.0, (float) $stock->productStock);
    }

    public function test_the_total_matches_the_sum_of_its_lines(): void
    {
        $first = $this->product();
        $second = $this->product();

        $this->purchase([
            $this->line($first, ['quantities' => 3, 'productPurchasePrice' => 33.33]),
            $this->line($second, ['quantities' => 3, 'productPurchasePrice' => 66.67]),
        ], ['paidAmount' => 300])->assertStatus(200);

        $lineSum = PurchaseDetails::sum('totalPurchasePrice');

        $this->assertSame(300.0, round((float) $lineSum, 2));
        $this->assertSame(300.0, (float) Purchase::first()->totalAmount);
    }

    public function test_a_retried_purchase_creates_one_record(): void
    {
        $product = $this->product();

        $body = [
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'paidAmount' => 500,
            'products' => [$this->line($product)],
        ];

        $headers = ['Idempotency-Key' => 'purchase-retry-001'];

        $this->actingAs($this->user)->postJson('/api/v1/purchase', $body, $headers)->assertStatus(200);
        $this->actingAs($this->user)->postJson('/api/v1/purchase', $body, $headers)->assertStatus(200);

        $this->assertSame(1, Purchase::count(), 'The retry created a second purchase.');

        // And stock moved only once.
        $this->assertSame(10.0, (float) Stock::where('product_id', $product->id)->first()->productStock);
    }

    /**
     * A purchase records the rate it was taxed at, so input tax files rate by rate.
     *
     * `purchases.vat_percent` is what the GST report groups and filters input
     * tax on, and nothing ever wrote it — the same gap the sale side had, so
     * every purchase filed under "0% GST" and choosing a rate matched nothing.
     */
    public function test_a_purchase_records_the_rate_it_was_taxed_at(): void
    {
        $product = $this->product(rate: 12);

        $this->purchase([$this->line($product)])->assertSuccessful();

        $this->assertEqualsWithDelta(
            12.0,
            (float) Purchase::latest('id')->first()->vat_percent,
            0.01,
        );
    }

    /** An untaxed purchase files under nothing rather than a made-up rate. */
    public function test_an_untaxed_purchase_records_no_rate(): void
    {
        $product = $this->product();

        $this->purchase([$this->line($product)])->assertSuccessful();

        $this->assertEqualsWithDelta(
            0.0,
            (float) Purchase::latest('id')->first()->vat_percent,
            0.01,
        );
    }

    /**
     * A per-line discount survives being saved.
     *
     * The calculator read `discount_percent`/`discount_flat`, subtracted the
     * money from the line net, and then wrote every other field of the line
     * away - there was no column for it. So the discount lived only inside the
     * total it had already changed: re-opening the purchase showed each line at
     * full price, and saving it back made that true.
     */
    public function test_a_line_discount_is_kept_and_can_be_read_back(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product, ['discount_percent' => 10]),
        ])->assertStatus(200);

        $row = DB::table('purchase_details')->where('product_id', $product->id)->first();

        // 10 x 50 = 500 gross, 10% off = 50.
        $this->assertSame(10.0, (float) $row->discountPercent);
        $this->assertSame(50.0, (float) $row->discountAmount);
        $this->assertSame(450.0, (float) $row->totalPurchasePrice);
    }

    /** A flat discount is kept as a flat discount, not converted to a rate. */
    public function test_a_flat_line_discount_keeps_its_own_shape(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product, ['discount_flat' => 75]),
        ])->assertStatus(200);

        $row = DB::table('purchase_details')->where('product_id', $product->id)->first();

        $this->assertSame(75.0, (float) $row->discountFlat);
        $this->assertSame(0.0, (float) $row->discountPercent);
        $this->assertSame(75.0, (float) $row->discountAmount);
        $this->assertSame(425.0, (float) $row->totalPurchasePrice);
    }

    /** A line with no discount records none, rather than a stray zero-ish value. */
    public function test_a_line_without_a_discount_records_none(): void
    {
        $product = $this->product();

        $this->purchase([$this->line($product)])->assertStatus(200);

        $row = DB::table('purchase_details')->where('product_id', $product->id)->first();

        $this->assertSame(0.0, (float) $row->discountAmount);
        $this->assertSame(500.0, (float) $row->totalPurchasePrice);
    }

    /**
     * A mistyped batch number can be corrected while nothing has left the batch.
     *
     * `resolveStock` filled the row from a value list that never contained
     * `batch_no`, so editing the number did nothing at all: the save reported
     * success and the old number came back on reload.
     */
    public function test_a_batch_number_can_be_corrected_before_anything_sells(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product, ['batch_no' => 'TYPO-1']),
        ])->assertStatus(200);

        $stockId = DB::table('stocks')->where('product_id', $product->id)->value('id');

        $this->purchase([
            $this->line($product, ['batch_no' => 'CORRECT-1', 'stock_id' => $stockId]),
        ])->assertStatus(200);

        $this->assertSame(
            'CORRECT-1',
            DB::table('stocks')->where('id', $stockId)->value('batch_no'),
        );

        // Corrected, not duplicated: a second row would strand the first one's
        // quantity under the old name.
        $this->assertSame(1, DB::table('stocks')->where('product_id', $product->id)->count());
    }

    /**
     * Once stock has moved, the batch keeps the name it moved under.
     *
     * The number is on invoices and inventory events that cannot be reissued.
     */
    public function test_a_batch_number_cannot_be_changed_once_stock_has_moved(): void
    {
        $product = $this->product();

        $this->purchase([
            $this->line($product, ['batch_no' => 'SOLD-1']),
        ])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        // An inventory event is the per-lot record a sale writes.
        DB::table('inventory_change_logs')->insert([
            'business_id' => $this->business->id,
            'event_type' => 'sale_out',
            'source_type' => 'App\\Models\\Sale',
            'source_id' => 1,
            'stock_id' => $stockId,
            'product_id' => $product->id,
            'quantity_delta' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->purchase([
            $this->line($product, ['batch_no' => 'RENAMED-1', 'stock_id' => $stockId]),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'BATCH_IDENTITY_PROTECTED')
            ->assertJsonPath('current_batch', 'SOLD-1')
            ->assertJsonPath('attempted_batch', 'RENAMED-1');

        $this->assertSame(
            'SOLD-1',
            DB::table('stocks')->where('id', $stockId)->value('batch_no'),
            'the batch must keep the name its history was written under',
        );
    }

    /** A rejected rename must not have moved any stock on its way out. */
    public function test_a_rejected_batch_rename_changes_no_inventory(): void
    {
        $product = $this->product();

        $this->purchase([$this->line($product, ['batch_no' => 'KEEP-1'])])->assertStatus(200);

        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        DB::table('inventory_change_logs')->insert([
            'business_id' => $this->business->id,
            'event_type' => 'sale_out',
            'source_type' => 'App\\Models\\Sale',
            'source_id' => 1,
            'stock_id' => $stockId,
            'product_id' => $product->id,
            'quantity_delta' => -1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = [
            'lots' => (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity'),
            'stock' => (float) DB::table('stocks')->where('id', $stockId)->value('productStock'),
            'details' => DB::table('purchase_details')->where('stock_id', $stockId)->count(),
        ];

        $this->purchase([
            $this->line($product, ['batch_no' => 'NOPE-1', 'stock_id' => $stockId]),
        ])->assertStatus(409);

        $this->assertSame($before['lots'], (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity'));
        $this->assertSame($before['stock'], (float) DB::table('stocks')->where('id', $stockId)->value('productStock'));
        $this->assertSame($before['details'], DB::table('purchase_details')->where('stock_id', $stockId)->count());
    }
}

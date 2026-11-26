<?php

namespace Tests\Feature\Api;

use App\Imports\SaleImport;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use App\Services\InventoryChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lot lineage through the endpoints a shop actually uses.
 *
 * The unit tests prove `BatchConsumption` allocates and reverses correctly.
 * These prove the till, the sale editor, the cancel button and the importer all
 * reach that same code - the divergence that let two FIFO implementations exist
 * in one controller is exactly how the per-lot defect survived so long.
 */
class SaleLineageEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Product $product;
    private Stock $stock;
    private int $lotA;
    private int $lotB;
    private Customer $customer;

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
            'productName' => 'E2E Item',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'E2E-1',
            'productStock' => 0,
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
            'batch_no' => 'E2E-BATCH',
            'productStock' => 70,
            'conversionValue' => 1,
            'productPurchasePrice' => 10,
            'productSalePrice' => 20,
        ]);

        $this->lotA = $this->lot(30);
        $this->lotB = $this->lot(40);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name' => 'Walk-in',
            'phone' => '9999900000',
        ]);

        $this->actingAs($this->user);
    }

    private function lot(float $quantity): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id' => $this->business->id,
            'invoiceNumber' => 'P-'.uniqid(),
            'purchaseDate' => '2026-07-01',
            'totalAmount' => 0, 'paidAmount' => 0, 'dueAmount' => 0,
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
            'purchaseUpperLimit' => '', 'purchaseLowerLimit' => '',
            'totalPrice' => 0, 'totalPurchasePrice' => 0,
        ]);
    }

    private function held(int $lotId): float
    {
        return (float) DB::table('purchase_details')->where('id', $lotId)->value('conversionQuantity');
    }

    private function sellPayload(float $quantity): array
    {
        return [
            'saleDate' => '2026-08-01',
            'customer_id' => $this->customer->id,
            'totalAmount' => $quantity * 20,
            'paidAmount' => $quantity * 20,
            'products' => json_encode([[
                'stock_id' => $this->stock->id,
                'quantities' => $quantity,
                'price' => 20,
                'lossProfit' => 10,
            ]]),
        ];
    }

    public function test_a_till_sale_records_lineage_for_every_lot_it_touches(): void
    {
        $this->postJson('/api/v1/sales', $this->sellPayload(50))->assertSuccessful();

        $sale = Sale::where('business_id', $this->business->id)->firstOrFail();

        $events = InventoryChangeLog::where('source_id', $sale->id)
            ->where('event_type', InventoryChangeService::SALE_OUT)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events, 'a sale across two lots needs two events');
        $this->assertSame($this->lotA, (int) $events[0]->purchase_detail_id);
        $this->assertSame(-30.0, (float) $events[0]->quantity_delta);
        $this->assertSame($this->lotB, (int) $events[1]->purchase_detail_id);
        $this->assertSame(-20.0, (float) $events[1]->quantity_delta);
        $this->assertSame(1, $events->pluck('correlation_id')->unique()->count());
    }

    public function test_cancelling_a_sale_gives_each_lot_back_what_it_lent(): void
    {
        $this->postJson('/api/v1/sales', $this->sellPayload(50))->assertSuccessful();
        $sale = Sale::where('business_id', $this->business->id)->firstOrFail();

        $this->assertSame(0.0, $this->held($this->lotA));
        $this->assertSame(20.0, $this->held($this->lotB));

        $this->deleteJson("/api/v1/sales/{$sale->id}")->assertSuccessful();

        $this->assertSame(30.0, $this->held($this->lotA), 'lot A must be made whole');
        $this->assertSame(40.0, $this->held($this->lotB), 'lot B must not absorb A\'s stock');

        $this->assertSame(70.0, (float) DB::table('stocks')->where('id', $this->stock->id)->value('productStock'));
        $this->assertSame(70.0, (float) DB::table('products')->where('id', $this->product->id)->value('productStock'));
    }

    public function test_editing_a_sale_reverses_the_original_allocation_then_reconsumes(): void
    {
        $this->postJson('/api/v1/sales', $this->sellPayload(50))->assertSuccessful();
        $sale = Sale::where('business_id', $this->business->id)->firstOrFail();

        $this->putJson("/api/v1/sales/{$sale->id}", $this->sellPayload(10))->assertSuccessful();

        // 70 held, 10 sold: the older lot supplies it, so A is down 10 and B is full.
        $this->assertSame(20.0, $this->held($this->lotA));
        $this->assertSame(40.0, $this->held($this->lotB));

        $reversals = InventoryChangeLog::where('source_id', $sale->id)
            ->where('event_type', InventoryChangeService::SALE_REVERSAL_IN)
            ->pluck('quantity_delta', 'purchase_detail_id');

        $this->assertSame(30.0, (float) $reversals[$this->lotA]);
        $this->assertSame(20.0, (float) $reversals[$this->lotB]);

        $this->assertSame(60.0, (float) DB::table('stocks')->where('id', $this->stock->id)->value('productStock'));
    }

    public function test_a_rejected_sale_leaves_lots_tallies_and_events_untouched(): void
    {
        // 406: the product-level availability check refuses before the batch
        // check is reached. Which of the two answers is not the point here -
        // that the refusal moved nothing is.
        $this->postJson('/api/v1/sales', $this->sellPayload(500))->assertStatus(406);

        $this->assertSame(30.0, $this->held($this->lotA));
        $this->assertSame(40.0, $this->held($this->lotB));
        $this->assertSame(70.0, (float) DB::table('stocks')->where('id', $this->stock->id)->value('productStock'));
        $this->assertSame(0, InventoryChangeLog::count());
    }

    public function test_an_imported_sale_uses_the_same_lineage_path(): void
    {
        $import = new SaleImport($this->business->id, $this->user->id);

        $import->collection(new Collection([
            ['code', 'qty', 'price', 'batch'],
            ['E2E-1', 50, 20, 'E2E-BATCH'],
        ]));

        $sale = Sale::where('business_id', $this->business->id)->firstOrFail();

        $events = InventoryChangeLog::where('source_id', $sale->id)
            ->where('event_type', InventoryChangeService::SALE_OUT)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $events, 'an import must record lineage like the till');
        $this->assertSame($this->lotA, (int) $events[0]->purchase_detail_id);
        $this->assertSame(-30.0, (float) $events[0]->quantity_delta);
        $this->assertSame(-20.0, (float) $events[1]->quantity_delta);
        $this->assertSame(Sale::class, $events[0]->source_type);
    }

    /**
     * A per-line sale discount survives being saved.
     *
     * `CartCalculationService` worked the discount out and took it off the line
     * net; `toSaleDetail()` then wrote the price, quantity and profit and
     * nothing else. Re-opening the sale showed the line at full price, and
     * saving it back made that true - the discount was gone for good on the
     * second save.
     */
    public function test_a_line_discount_is_kept_on_the_sale(): void
    {
        $payload = $this->sellPayload(10);
        $payload['products'] = json_encode([[
            'stock_id' => $this->stock->id,
            'quantities' => 10,
            'price' => 20,
            'lossProfit' => 10,
            'discount_percent' => 10,
        ]]);

        $this->postJson('/api/v1/sales', $payload)->assertSuccessful();

        $row = DB::table('sale_details')->where('stock_id', $this->stock->id)->first();

        // 10 x 20 = 200 gross, 10% off = 20.
        $this->assertSame(10.0, (float) $row->discountPercent);
        $this->assertSame(20.0, (float) $row->discountAmount);
    }

    /** A line with no discount records none. */
    public function test_a_sale_line_without_a_discount_records_none(): void
    {
        $this->postJson('/api/v1/sales', $this->sellPayload(10))->assertSuccessful();

        $row = DB::table('sale_details')->where('stock_id', $this->stock->id)->first();

        $this->assertSame(0.0, (float) $row->discountAmount);
    }
}

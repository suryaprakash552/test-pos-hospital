<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every inventory movement must name the lot it moved.
 *
 * Sales already did. Returns and disposals wrote one event for the whole batch,
 * so the history could say stock left or arrived without saying which lot it
 * came from - and on a batch bought over several lots at several prices, that
 * is the only question the audit exists to answer.
 */
class LotTraceabilityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Category $category;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id, 'role' => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id, 'name' => 'FINAL_E2E Sup', 'type' => 'Supplier', 'due' => 0,
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'FINAL_E2E Cust', 'phone' => '9000000301',
        ]);

        $this->actingAs($this->user);
    }

    private function record(string $k, array $d): void
    {
        fwrite(STDERR, "\n[EVIDENCE] {$k}: ".json_encode($d)."\n");
    }

    private function product(string $code): Product
    {
        return Product::create([
            'productName' => 'FINAL_E2E '.$code,
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'productCode' => $code, 'productStock' => 0,
        ]);
    }

    private function buy(Product $p, array $o = [])
    {
        return $this->postJson('/api/v1/purchase', [
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [array_merge([
                'product_id' => $p->id, 'batch_no' => 'FE2E-'.$p->productCode,
                'quantities' => 30, 'productPurchasePrice' => 50, 'productSalePrice' => 80,
                'conversion_purchase_price' => 50, 'conversion_quantities' => 30,
                'conversion_value' => 1, 'conversion_price' => 80,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 1500, 'total_price' => 2400,
            ], $o)],
        ]);
    }

    /** @return array<int,array{event_type:string,purchase_detail_id:?int,quantity_delta:float}> */
    private function events(string $type): array
    {
        return InventoryChangeLog::where('event_type', $type)->orderBy('id')->get()
            ->map(fn ($e) => [
                'event_type' => $e->event_type,
                'purchase_detail_id' => $e->purchase_detail_id === null ? null : (int) $e->purchase_detail_id,
                'quantity_delta' => (float) $e->quantity_delta,
                'source_type' => $e->source_type,
                'correlation_id' => $e->correlation_id,
                'user_id' => $e->user_id,
                'business_id' => (int) $e->business_id,
            ])->all();
    }

    public function test_a_multi_lot_disposal_records_one_event_per_lot(): void
    {
        $product = $this->product('DISPLOT');

        $this->buy($product, ['quantities' => 30, 'conversion_quantities' => 30])->assertStatus(200);
        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');
        $this->buy($product, ['quantities' => 20, 'conversion_quantities' => 20, 'stock_id' => $stockId])->assertStatus(200);

        $lots = DB::table('purchase_details')->where('stock_id', $stockId)->orderBy('id')->pluck('id')->all();
        $this->assertCount(2, $lots);

        DB::table('stocks')->where('id', $stockId)->update(['expire_date' => now()->subDay()->toDateString()]);

        $this->postJson('/api/v1/stock-disposals', [
            'stock_ids' => [$stockId], 'reason' => 'FINAL_E2E disposal',
        ], ['Idempotency-Key' => 'fe2e-'.uniqid()])->assertSuccessful();

        $events = $this->events('disposal_out');
        $this->record('A3/multi_lot_disposal', $events);

        $this->assertCount(2, $events, 'two lots emptied means two events');
        $this->assertSame($lots[0], $events[0]['purchase_detail_id']);
        $this->assertSame(-30.0, $events[0]['quantity_delta']);
        $this->assertSame($lots[1], $events[1]['purchase_detail_id']);
        $this->assertSame(-20.0, $events[1]['quantity_delta']);

        // Every event answers who, when, which tenant.
        foreach ($events as $e) {
            $this->assertSame($this->business->id, $e['business_id']);
            $this->assertNotNull($e['user_id']);
            $this->assertNotNull($e['correlation_id']);
        }

        $this->assertSame(0.0, (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity'));
    }

    public function test_a_purchase_return_names_the_lot_it_reduced(): void
    {
        $product = $this->product('PRETLOT');
        $this->buy($product)->assertStatus(200);

        $purchaseId = (int) DB::table('purchases')->value('id');
        $lotId = (int) DB::table('purchase_details')->value('id');

        $this->postJson('/api/v1/purchases-return', [
            'purchase_id' => $purchaseId,
            'return_date' => now()->toDateString(),
            'purchase_detail_id' => [$lotId],
            'return_qty' => [5],
            'return_amount' => [250],
        ])->assertStatus(200);

        $events = $this->events('purchase_return_out');
        $this->record('A2/purchase_return', $events);

        $this->assertCount(1, $events);
        $this->assertSame($lotId, $events[0]['purchase_detail_id'], 'the exact lot reduced must be named');
        $this->assertSame(-5.0, $events[0]['quantity_delta']);
        $this->assertSame(25.0, (float) DB::table('purchase_details')->where('id', $lotId)->value('conversionQuantity'));
    }

    public function test_a_sale_return_names_every_lot_it_credited(): void
    {
        $product = $this->product('SRETLOT');
        $this->buy($product)->assertStatus(200);
        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');
        $lotId = (int) DB::table('purchase_details')->value('id');

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => 800, 'paidAmount' => 800,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 10, 'price' => 80, 'lossProfit' => 30,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->value('id');
        $detailId = (int) DB::table('sale_details')->value('id');

        $this->postJson('/api/v1/sales-return', [
            'sale_id' => $saleId,
            'return_date' => now()->toDateString(),
            'sale_detail_id' => [$detailId],
            'return_qty' => [4],
            'return_amount' => [320],
        ])->assertStatus(200);

        $events = $this->events('sale_return_in');
        $this->record('A1/sale_return', $events);

        $this->assertCount(1, $events);
        $this->assertSame($lotId, $events[0]['purchase_detail_id'], 'the credited lot must be named');
        $this->assertSame(4.0, $events[0]['quantity_delta']);

        // 30 bought, 10 sold, 4 back = 24.
        $this->assertSame(24.0, (float) DB::table('purchase_details')->where('id', $lotId)->value('conversionQuantity'));
    }

    /** The net of every recorded movement must equal what the lots hold. */
    public function test_the_events_sum_to_the_authoritative_balance(): void
    {
        $product = $this->product('NETSUM');
        $this->buy($product)->assertStatus(200);
        $stockId = (int) DB::table('stocks')->where('product_id', $product->id)->value('id');

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => 640, 'paidAmount' => 640,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 8, 'price' => 80, 'lossProfit' => 30,
            ]]),
        ])->assertSuccessful();

        $netFromEvents = (float) InventoryChangeLog::where('stock_id', $stockId)->sum('quantity_delta');
        $authoritative = (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity');

        $this->record('A4/net_reconciles', [
            'net_from_events' => $netFromEvents,
            'authoritative_lots' => $authoritative,
        ]);

        $this->assertSame($authoritative, $netFromEvents, 'the ledger must explain the balance');
    }
}

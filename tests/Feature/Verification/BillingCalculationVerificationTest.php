<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryChangeLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the bill says, what the shelf loses, and what the ledger records - all
 * from one transaction.
 *
 * A pharmacy POS gets judged on three things at once: the customer is charged
 * the right amount, the right units leave the right batch, and an inspector can
 * later be shown which. Verifying them separately can leave all three correct
 * on their own and inconsistent with each other, so every case here asserts the
 * money, the lot balance and the event trail from the same sale.
 *
 * Every expected figure is computed independently in the test, never read back
 * from the thing under test.
 */
class BillingCalculationVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private Customer $customer;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id, 'role' => 'shop-owner',
        ]);
        $this->supplier = Party::create([
            'business_id' => $this->business->id, 'name' => 'CALC Supplier', 'type' => 'Supplier', 'due' => 0,
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id, 'name' => 'CALC Customer', 'phone' => '9200000001',
        ]);

        $this->actingAs($this->user);
    }

    private function say(string $k, array $d): void
    {
        fwrite(STDERR, "\n[CALC] {$k}: ".json_encode($d)."\n");
    }

    /**
     * A medicine sold in strips of [conversionValue] tablets.
     *
     * Prices are per strip on the product; the lot carries the per-tablet
     * figures the till actually bills from.
     */
    private function medicine(string $code, float $conversionValue = 1, ?int $vatId = null): Product
    {
        return Product::create([
            'productName' => 'CALC '.$code,
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'productCode' => $code,
            'productStock' => 0,
            'conversionValue' => $conversionValue,
            'vat_id' => $vatId,
            // NOT NULL on this table; an untaxed product is still 'exclusive'
            // with no rate behind it.
            'vat_type' => 'exclusive',
        ]);
    }

    private function stockUp(Product $p, float $strips, float $conversionValue, float $costPerStrip, float $salePerStrip): int
    {
        $tablets = $strips * $conversionValue;

        $this->postJson('/api/v1/purchase', [
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $p->id, 'batch_no' => 'CALC-'.$p->productCode,
                'quantities' => $strips,
                'productPurchasePrice' => $costPerStrip,
                'productSalePrice' => $salePerStrip,
                'conversion_purchase_price' => $costPerStrip / $conversionValue,
                'conversion_quantities' => $tablets,
                'conversion_value' => $conversionValue,
                'conversion_price' => $salePerStrip / $conversionValue,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => $costPerStrip * $strips,
                'total_price' => $salePerStrip * $strips,
            ]],
        ])->assertStatus(200);

        return (int) DB::table('stocks')->where('product_id', $p->id)->value('id');
    }

    private function saleRow(int $saleId): array
    {
        return (array) DB::table('sales')->where('id', $saleId)->first([
            'totalAmount', 'actual_total_amount', 'rounding_amount', 'vat_amount',
            'discountAmount', 'rowDiscount', 'paidAmount', 'dueAmount',
            'change_amount', 'lossProfit', 'vat_percent',
        ]);
    }

    private function lots(int $stockId): float
    {
        return (float) DB::table('purchase_details')->where('stock_id', $stockId)->sum('conversionQuantity');
    }

    // ══════════════════════════════════════════════════════════════════════

    /**
     * The central medical-POS case: billing loose tablets out of a strip.
     *
     * A strip of 10 costing 200 to sell means 20 a tablet. Selling 7 tablets
     * must charge 140, take 7 tablets - not 7 strips - off the batch, and leave
     * an event saying so.
     */
    public function test_loose_tablets_are_billed_and_deducted_per_tablet(): void
    {
        $product = $this->medicine('STRIP10', 10);
        $stockId = $this->stockUp($product, strips: 5, conversionValue: 10, costPerStrip: 120, salePerStrip: 200);

        // 5 strips of 10 = 50 tablets on the shelf.
        $this->assertSame(50.0, $this->lots($stockId));

        $tabletPrice = 200 / 10.0;            // 20.00 (float: PHP's / yields int when exact)
        $quantity = 7;
        $expectedLine = (float) ($tabletPrice * $quantity);  // 140.00

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => $expectedLine, 'paidAmount' => $expectedLine,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => $quantity,
                'price' => $tabletPrice, 'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->value('id');
        $row = $this->saleRow($saleId);

        $this->say('tablets/bill', [
            'tablet_price' => $tabletPrice, 'qty' => $quantity,
            'expected' => $expectedLine, 'charged' => (float) $row['totalAmount'],
            'lots_after' => $this->lots($stockId),
        ]);

        $this->assertSame($expectedLine, (float) $row['totalAmount'], 'the customer must be charged per tablet');
        $this->assertSame(43.0, $this->lots($stockId), '7 tablets leave the shelf, not 7 strips');

        // Profit: 7 tablets at (20 - 12) each.
        $expectedProfit = (float) (((200 - 120) / 10.0) * $quantity);   // 56.00
        $this->assertSame($expectedProfit, (float) $row['lossProfit']);

        $event = InventoryChangeLog::where('event_type', 'sale_out')->first();
        $this->assertSame(-7.0, (float) $event->quantity_delta, 'the event must record tablets');
        $this->assertNotNull($event->purchase_detail_id);
    }

    /** A line discount reduces the charge, the profit and nothing else. */
    public function test_a_line_discount_reduces_charge_and_profit_but_not_stock(): void
    {
        $product = $this->medicine('LINEDISC');
        $stockId = $this->stockUp($product, strips: 20, conversionValue: 1, costPerStrip: 50, salePerStrip: 100);

        $qty = 4;
        $gross = 100 * $qty;          // 400
        $discount = $gross * 0.10;    // 40
        $net = $gross - $discount;    // 360

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => $net, 'paidAmount' => $net,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => $qty, 'price' => 100,
                'lossProfit' => 0, 'discount_percent' => 10,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->value('id');
        $row = $this->saleRow($saleId);
        $line = (array) DB::table('sale_details')->where('sale_id', $saleId)->first(['discountAmount', 'lossProfit']);

        $this->say('line_discount', [
            'gross' => $gross, 'discount' => $discount, 'expected_net' => $net,
            'charged' => (float) $row['totalAmount'], 'rowDiscount' => (float) $row['rowDiscount'],
            'line_discount' => (float) $line['discountAmount'], 'lots_after' => $this->lots($stockId),
        ]);

        $this->assertSame($net, (float) $row['totalAmount']);
        $this->assertSame($discount, (float) $row['rowDiscount']);
        $this->assertSame($discount, (float) $line['discountAmount']);

        // Profit absorbs the discount: (400 - 40) - (4 x 50) = 160.
        $this->assertSame(160.0, (float) $row['lossProfit']);

        // A discount is a price decision, not a stock one.
        $this->assertSame(16.0, $this->lots($stockId));
    }

    /** Tax is charged on the discounted value, not the sticker price. */
    public function test_tax_is_charged_on_the_discounted_value(): void
    {
        $vat = Vat::create(['business_id' => $this->business->id, 'name' => 'GST12', 'rate' => 12]);
        $product = $this->medicine('TAXED', 1, $vat->id);
        $stockId = $this->stockUp($product, strips: 20, conversionValue: 1, costPerStrip: 50, salePerStrip: 100);

        $qty = 5;
        $gross = 500.0;
        $discount = 100.0;                 // flat
        $net = $gross - $discount;         // 400
        $tax = round($net * 0.12, 2);      // 48
        $payable = $net + $tax;            // 448

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => $payable, 'paidAmount' => $payable,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => $qty, 'price' => 100,
                'lossProfit' => 0, 'discount_flat' => $discount,
            ]]),
        ])->assertSuccessful();

        $row = $this->saleRow((int) DB::table('sales')->value('id'));

        $this->say('tax_on_net', [
            'gross' => $gross, 'discount' => $discount, 'net' => $net,
            'expected_tax' => $tax, 'recorded_tax' => (float) $row['vat_amount'],
            'expected_total' => $payable, 'recorded_total' => (float) $row['totalAmount'],
            'vat_percent' => (float) $row['vat_percent'],
        ]);

        $this->assertSame($tax, (float) $row['vat_amount'], 'tax must follow the discounted value');
        $this->assertSame($payable, (float) $row['totalAmount']);
        $this->assertSame(12.0, (float) $row['vat_percent'], 'the rate charged must be recorded');
    }

    /** Paid, due and change must reconcile against the total. */
    public function test_payment_split_reconciles_with_the_total(): void
    {
        $product = $this->medicine('PAYSPLIT');
        $stockId = $this->stockUp($product, strips: 20, conversionValue: 1, costPerStrip: 50, salePerStrip: 100);

        $total = 300.0;
        $paid = 200.0;
        $due = $total - $paid;

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => $total, 'paidAmount' => $paid, 'dueAmount' => $due,
            'cash_amount' => 120, 'upi_amount' => 80, 'card_amount' => 0,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 3, 'price' => 100, 'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->value('id');
        $row = $this->saleRow($saleId);
        $tenders = (array) DB::table('sales')->where('id', $saleId)->first(['cash_amount', 'upi_amount', 'card_amount']);

        $this->say('payment_split', $row + $tenders);

        $this->assertSame($total, (float) $row['totalAmount']);
        $this->assertSame($paid, (float) $row['paidAmount']);
        $this->assertSame($due, (float) $row['dueAmount']);
        $this->assertSame(
            $paid,
            (float) $tenders['cash_amount'] + (float) $tenders['upi_amount'] + (float) $tenders['card_amount'],
            'the tenders must add up to what was paid'
        );
    }

    /**
     * A refund never exceeds the sale, and returns exactly what left.
     *
     * The money and the stock have to agree: refunding three tablets must put
     * three tablets back, and credit three tablets' worth.
     */
    public function test_a_partial_refund_matches_the_stock_it_returns(): void
    {
        $product = $this->medicine('REFUND', 10);
        $stockId = $this->stockUp($product, strips: 5, conversionValue: 10, costPerStrip: 120, salePerStrip: 200);

        $tabletPrice = 20.0;
        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => 200.0, 'paidAmount' => 200.0,
            'products' => json_encode([[
                'stock_id' => $stockId, 'quantities' => 10, 'price' => $tabletPrice, 'lossProfit' => 0,
            ]]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->value('id');
        $detailId = (int) DB::table('sale_details')->where('sale_id', $saleId)->value('id');

        $this->assertSame(40.0, $this->lots($stockId), '10 tablets sold from 50');

        $returnQty = 3;
        $this->postJson('/api/v1/sales-return', [
            'sale_id' => $saleId, 'return_date' => now()->toDateString(),
            'sale_detail_id' => [$detailId],
            'return_qty' => [$returnQty],
            'return_amount' => [$tabletPrice * $returnQty],
        ])->assertStatus(200);

        $credited = (float) DB::table('sale_return_details')->sum('return_amount');
        $lotsAfter = $this->lots($stockId);
        $inEvent = InventoryChangeLog::where('event_type', 'sale_return_in')->first();

        $this->say('partial_refund', [
            'tablet_price' => $tabletPrice, 'returned' => $returnQty,
            'expected_credit' => $tabletPrice * $returnQty, 'credited' => $credited,
            'lots_after' => $lotsAfter,
            'event_delta' => (float) $inEvent->quantity_delta,
            'event_lot' => (int) $inEvent->purchase_detail_id,
        ]);

        $this->assertSame($tabletPrice * $returnQty, $credited, 'the credit must be three tablets');
        $this->assertSame(43.0, $lotsAfter, 'three tablets must come back');
        $this->assertSame((float) $returnQty, (float) $inEvent->quantity_delta);
    }

    /**
     * The whole bill reconciles: lines, discount, tax, rounding, total.
     *
     * Checked as one equation rather than field by field, because each field
     * being individually plausible is exactly how a bill ends up not adding up.
     */
    public function test_the_bill_adds_up_across_several_lines(): void
    {
        $a = $this->medicine('MULTI-A');
        $b = $this->medicine('MULTI-B', 10);

        $stockA = $this->stockUp($a, strips: 20, conversionValue: 1, costPerStrip: 40, salePerStrip: 100);
        $stockB = $this->stockUp($b, strips: 10, conversionValue: 10, costPerStrip: 150, salePerStrip: 300);

        // 3 units at 100, plus 12 tablets at 30.
        $lineA = 3 * 100.0;       // 300
        $lineB = 12 * 30.0;       // 360
        $subtotal = $lineA + $lineB;

        $this->postJson('/api/v1/sales', [
            'saleDate' => now()->toDateString(),
            'customer_id' => $this->customer->id,
            'totalAmount' => $subtotal, 'paidAmount' => $subtotal,
            'products' => json_encode([
                ['stock_id' => $stockA, 'quantities' => 3, 'price' => 100, 'lossProfit' => 0],
                ['stock_id' => $stockB, 'quantities' => 12, 'price' => 30, 'lossProfit' => 0],
            ]),
        ])->assertSuccessful();

        $saleId = (int) DB::table('sales')->value('id');
        $row = $this->saleRow($saleId);

        $lineSum = (float) DB::table('sale_details')->where('sale_id', $saleId)
            ->selectRaw('SUM(price * quantities) t')->value('t');

        $reconstructed = $lineSum
            - (float) $row['rowDiscount']
            - (float) $row['discountAmount']
            + (float) $row['vat_amount']
            + (float) $row['rounding_amount'];

        $this->say('multi_line_bill', [
            'line_sum' => $lineSum,
            'rowDiscount' => (float) $row['rowDiscount'],
            'orderDiscount' => (float) $row['discountAmount'],
            'tax' => (float) $row['vat_amount'],
            'rounding' => (float) $row['rounding_amount'],
            'reconstructed' => round($reconstructed, 2),
            'recorded_total' => (float) $row['totalAmount'],
            'lots_A' => $this->lots($stockA), 'lots_B' => $this->lots($stockB),
        ]);

        $this->assertSame(
            (float) $row['totalAmount'],
            round($reconstructed, 2),
            'the bill must equal its own parts'
        );

        // Each line drew from its own batch, in its own unit.
        $this->assertSame(17.0, $this->lots($stockA));
        $this->assertSame(88.0, $this->lots($stockB));

        // Two lines, two lot movements.
        $this->assertSame(2, InventoryChangeLog::where('event_type', 'sale_out')->count());
    }
}

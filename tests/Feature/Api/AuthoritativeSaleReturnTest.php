<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\InventoryChangeLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\SaleReturnDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A refund is money leaving the business, so the server decides what it is worth.
 *
 * The amount is derived from the original sale line - the price the item actually
 * sold at, times the quantity coming back. These pin that a request claiming
 * otherwise gets nowhere.
 */
class AuthoritativeSaleReturnTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Customer $customer;
    private Sale $sale;
    private SaleDetails $detail;
    private Stock $stock;
    private ?int $purchaseId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'due' => 0,
        ]);

        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $this->stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productStock' => 5,
        ]);

        // A settled sale: 4 units at 100, nothing owing.
        $this->sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'invoiceNumber' => 'RET-1',
            'saleDate' => now(),
            'totalAmount' => 400,
            'paidAmount' => 400,
            'dueAmount' => 0,
            'lossProfit' => 160,
            'isPaid' => 1,
            'paymentType' => 'cash',
        ]);

        $this->detail = SaleDetails::create([
            'sale_id' => $this->sale->id,
            'product_id' => $product->id,
            'stock_id' => $this->stock->id,
            'price' => 100,
            'quantities' => 4,
            'lossProfit' => 160,
        ]);

        // Normal returns must restore a real authoritative lot. The old test
        // fixture represented the historical corruption this suite now guards
        // against: a tally existed but no source lot did.
        $this->lot(purchased: 10, remaining: 5);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function returnSale(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales-return', array_merge([
            'sale_id' => $this->sale->id,
            'return_date' => now()->toDateString(),
            'sale_detail_id' => [$this->detail->id],
            'return_qty' => [1],
            'return_amount' => [100],
        ], $overrides));
    }

    public function test_the_refund_comes_from_the_sale_not_the_request(): void
    {
        // Claims each returned unit is worth 5000.
        $this->returnSale(['return_amount' => [5000]])->assertStatus(200);

        $this->assertSame(100.0, (float) SaleReturnDetails::first()->return_amount);
    }

    public function test_a_refund_cannot_exceed_what_was_sold(): void
    {
        $this->returnSale([
            'return_qty' => [99],
            'return_amount' => [9900],
        ])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'return_exceeds_sold');

        $this->assertSame(0, SaleReturnDetails::count());
        $this->assertSame(5.0, (float) $this->stock->fresh()->productStock);
    }

    public function test_a_lotless_legacy_batch_is_rejected_without_tally_or_event_changes(): void
    {
        DB::table('purchase_details')->where('stock_id', $this->stock->id)->delete();

        $this->returnSale(['return_qty' => [1]])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'inventory_lot_restoration_unavailable');

        $this->assertSame(5.0, (float) $this->stock->fresh()->productStock);
        $this->assertDatabaseCount('sale_return_details', 0);
        $this->assertDatabaseMissing('inventory_change_logs', [
            'event_type' => 'sale_return_in',
            'stock_id' => $this->stock->id,
        ]);
    }

    public function test_the_sale_total_is_reduced_by_the_calculated_refund(): void
    {
        $this->returnSale([
            'return_qty' => [2],
            // Claims the sale should be rewritten to something else entirely.
            'totalAmount' => 1,
            'dueAmount' => 9999,
            'paidAmount' => 0,
        ])->assertStatus(200);

        $sale = $this->sale->fresh();

        // 400 less two units at 100.
        $this->assertSame(200.0, (float) $sale->totalAmount);
        $this->assertSame(0.0, (float) $sale->dueAmount);
    }

    public function test_stock_is_restored_exactly_once(): void
    {
        $this->returnSale(['return_qty' => [2]])->assertStatus(200);

        $this->assertSame(7.0, (float) $this->stock->fresh()->productStock);
        $this->assertDatabaseHas('inventory_change_logs', [
            'event_type' => 'sale_return_in',
            'stock_id' => $this->stock->id,
            'quantity_delta' => 2,
        ]);
    }

    public function test_the_returned_quantity_comes_off_the_sale_line(): void
    {
        $this->returnSale(['return_qty' => [3]])->assertStatus(200);

        $this->assertSame(1.0, (float) $this->detail->fresh()->quantities);
    }

    public function test_a_refund_on_a_settled_sale_does_not_credit_the_customer_balance(): void
    {
        // Nothing was owing, so the refund is cash back - not a due reduction that
        // would leave the customer with a negative balance.
        $this->returnSale(['return_qty' => [1]])->assertStatus(200);

        $this->assertSame(0.0, (float) $this->customer->fresh()->due);
        $this->assertSame(100.0, (float) SaleReturnDetails::first()->return_cash);
    }

    public function test_a_return_keeps_its_cash_upi_and_card_refund_split(): void
    {
        $this->returnSale([
            'cash_amount' => 20,
            'upi_amount' => 30,
            'card_amount' => 50,
        ])->assertStatus(200);

        $return = SaleReturnDetails::firstOrFail();

        $this->assertSame(20.0, (float) $return->return_cash);
        $this->assertSame(30.0, (float) $return->return_upi);
        $this->assertSame(50.0, (float) $return->return_card);
    }

    public function test_a_refund_settles_an_outstanding_due_first(): void
    {
        $this->sale->update(['dueAmount' => 150, 'paidAmount' => 250]);
        $this->customer->update(['due' => 150]);

        // Returning 2 units = 200 refund against a 150 due.
        $this->returnSale(['return_qty' => [2]])->assertStatus(200);

        // The due clears, and only the surplus is cash.
        $this->assertSame(0.0, (float) $this->customer->fresh()->due);
        $this->assertSame(0.0, (float) $this->sale->fresh()->dueAmount);
        $this->assertSame(50.0, (float) SaleReturnDetails::first()->return_cash);
    }

    public function test_another_sales_line_cannot_be_returned(): void
    {
        $otherSale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'RET-2',
            'saleDate' => now(),
            'totalAmount' => 100,
            'paidAmount' => 100,
            'dueAmount' => 0,
        ]);

        $foreignDetail = SaleDetails::create([
            'sale_id' => $otherSale->id,
            'product_id' => $this->detail->product_id,
            'stock_id' => $this->stock->id,
            'price' => 100,
            'quantities' => 1,
            'lossProfit' => 40,
        ]);

        $this->returnSale(['sale_detail_id' => [$foreignDetail->id]])
            ->assertStatus(404)
            ->assertJsonPath('reason', 'unknown_return_line');

        $this->assertSame(0, SaleReturnDetails::count());
    }

    public function test_another_tenants_sale_cannot_be_returned(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignSale = Sale::create([
            'business_id' => $otherBusiness->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'RET-3',
            'saleDate' => now(),
            'totalAmount' => 100,
            'paidAmount' => 100,
            'dueAmount' => 0,
        ]);

        $this->returnSale(['sale_id' => $foreignSale->id])->assertStatus(404);

        $this->assertSame(0, SaleReturnDetails::count());
    }

    /** @return array<string, array{mixed}> */
    public static function provideInvalidQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-2],
            'not a number' => ['abc'],
        ];
    }

    /** @dataProvider provideInvalidQuantities */
    public function test_invalid_return_quantities_are_rejected(mixed $quantity): void
    {
        $this->returnSale(['return_qty' => [$quantity]])->assertStatus(422);

        $this->assertSame(0, SaleReturnDetails::count());
        $this->assertSame(5.0, (float) $this->stock->fresh()->productStock);
    }

    /**
     * The figure the shop actually sees comes back.
     *
     * `available_quantity` — what the product list, the batch popup and the
     * sale endpoint's own stock check all read — is the sum of the purchase
     * lots' `conversionQuantity`, which a sale drains FIFO. The return credited
     * only `stocks.productStock`, a tally nothing on screen reads, so returned
     * goods sat on the shelf while the app said "0 in stock".
     */
    public function test_a_return_puts_the_stock_back_into_the_purchase_lots(): void
    {
        $lot = $this->lot(purchased: 4, remaining: 0);

        $this->returnSale(['return_qty' => [3]])->assertStatus(200);

        $this->assertSame(3.0, (float) DB::table('purchase_details')
            ->where('id', $lot)->value('conversionQuantity'));
    }

    /** A lot is never refilled past what it was bought with. */
    public function test_restoring_stock_respects_what_each_lot_held(): void
    {
        $older = $this->lot(purchased: 1, remaining: 0);
        $newer = $this->lot(purchased: 1, remaining: 0);

        $this->returnSale(['return_qty' => [2]])->assertStatus(200);

        // Newest first, mirroring the FIFO order the sale consumed them in.
        $this->assertSame(1.0, (float) DB::table('purchase_details')
            ->where('id', $newer)->value('conversionQuantity'));
        $this->assertSame(1.0, (float) DB::table('purchase_details')
            ->where('id', $older)->value('conversionQuantity'));
    }

    /**
     * `productStock` counts packs; a return quantity counts tablets.
     *
     * The sale divides by the conversion before decrementing. The return did
     * not, so handing back 10 tablets of a 10-per-pack batch credited 10 packs.
     */
    public function test_stock_is_credited_in_upper_units(): void
    {
        // 40 tablets sold at ₹10, which is the ₹400 the sale was rung up for.
        // Valid initial state: 50 lower units / 10 per pack = 5 packs. The
        // return restores 20 lower units, so 70 / 10 = 7 packs. The old fixture
        // added a second 10-per-pack lot to an existing one-per-pack lot while
        // leaving the stored tally at 5; its authoritative total was already 6.
        DB::table('purchase_details')->where('stock_id', $this->stock->id)->update([
            'quantities' => 10,
            'conversionQuantity' => 50,
            'conversionValue' => 10,
        ]);
        $this->stock->update(['conversionValue' => 10, 'productStock' => 5]);
        Product::whereKey($this->detail->product_id)->update(['productStock' => 5]);
        $this->detail->update(['quantities' => 40, 'price' => 10]);

        $this->returnSale(['return_qty' => [20]])->assertStatus(200);

        $this->assertSame(7.0, (float) $this->stock->fresh()->productStock);
    }

    /**
     * Refunded cash is not money the sale still holds.
     *
     * A settled ₹400 sale with ₹100 returned read "Cash paid ₹400" beside a
     * ₹300 bill, and the day's takings counted the refund as revenue.
     */
    public function test_a_refund_reduces_what_the_sale_was_paid(): void
    {
        $this->returnSale()->assertStatus(200);

        $sale = $this->sale->fresh();

        $this->assertSame(300.0, (float) $sale->totalAmount);
        $this->assertSame(300.0, (float) $sale->paidAmount);
        $this->assertSame(0.0, (float) $sale->dueAmount);
    }

    /** A refund that only cancels a due was never paid, so paid is untouched. */
    public function test_a_refund_against_a_due_leaves_what_was_paid_alone(): void
    {
        $this->sale->update(['dueAmount' => 150, 'paidAmount' => 250]);

        $this->returnSale()->assertStatus(200);

        $sale = $this->sale->fresh();

        $this->assertSame(250.0, (float) $sale->paidAmount);
        $this->assertSame(50.0, (float) $sale->dueAmount);
        $this->assertSame(300.0, (float) $sale->totalAmount);
    }

    /**
     * A bill that rounding reduced can still be returned in full.
     *
     * `totalAmount` is the rounded figure the customer was billed; a refund is
     * built from the unrounded line prices. On a ₹70.30 sale rounded down to
     * ₹70, returning the only line produced a ₹70.30 refund and the guard
     * refused it — "The refund cannot be greater than the sale it belongs to"
     * — so a full return was impossible on every rounded-down bill.
     */
    public function test_a_rounded_down_sale_can_still_be_returned_in_full(): void
    {
        $this->detail->update(['quantities' => 1, 'price' => 70.30]);
        $this->sale->update([
            'actual_total_amount' => 70.30,
            'totalAmount' => 70,
            'paidAmount' => 70,
            'dueAmount' => 0,
            'rounding_option' => 'round_down',
            'rounding_amount' => -0.30,
        ]);

        $this->returnSale(['return_qty' => [1]])->assertStatus(200);

        $sale = $this->sale->fresh();

        // Never more than was taken: the shop collected ₹70, so ₹70 goes back.
        $this->assertSame(70.0, (float) SaleReturnDetails::first()->total_return);
        $this->assertSame(0.0, (float) $sale->totalAmount);
        $this->assertSame(0.0, (float) $sale->paidAmount);
    }

    /**
     * A refund rounds down, so the customer is never handed back more.
     *
     * ₹32.81 refunds ₹32, not ₹33: the extra 19 paise was never paid. The
     * rounding happens on the server so the figure the counter sees is the
     * figure stored — the app used to round for display only and call it a
     * preview, which meant the receipt and the record disagreed.
     */
    public function test_a_refund_rounds_down_rather_than_to_nearest(): void
    {
        $this->detail->update(['quantities' => 1, 'price' => 32.81]);
        $this->sale->update([
            'actual_total_amount' => 32.81,
            'totalAmount' => 33,
            'paidAmount' => 33,
            'dueAmount' => 0,
            'rounding_option' => 'nearest_whole_number',
        ]);

        $this->returnSale(['return_qty' => [1]])->assertStatus(200);

        $this->assertEqualsWithDelta(
            32.0,
            (float) SaleReturnDetails::first()->total_return,
            0.01,
        );
    }

    /** A whole-rupee refund is left alone rather than dropped a rupee. */
    public function test_an_exact_refund_is_not_reduced(): void
    {
        $this->detail->update(['quantities' => 1, 'price' => 100]);

        $this->returnSale(['return_qty' => [1]])->assertStatus(200);

        $this->assertEqualsWithDelta(
            100.0,
            (float) SaleReturnDetails::first()->total_return,
            0.01,
        );
    }

    /**
     * The line refunds add up to the rounded total.
     *
     * The lines are what the returned-items list, the return report and the
     * invoice print. Left at their raw values they summed to ₹32.81 beside a
     * ₹32 total, so a ₹32 return displayed the original price of the goods
     * rather than the credit actually given.
     */
    public function test_line_refunds_sum_to_the_rounded_total(): void
    {
        $this->detail->update(['quantities' => 1, 'price' => 32.81]);

        $this->returnSale(['return_qty' => [1]])->assertStatus(200);

        $lines = SaleReturnDetails::all();
        $sum = $lines->sum(fn ($line) => (float) $line->return_amount);

        $this->assertEqualsWithDelta(32.0, $sum, 0.01);
        $this->assertEqualsWithDelta(
            32.0,
            (float) $lines->first()->total_return,
            0.01,
        );
    }

    /** A share of the return cannot exceed the whole of it. */
    public function test_a_return_discount_over_one_hundred_percent_is_refused(): void
    {
        $this->returnSale(['return_discount_percent' => 1000])
            ->assertStatus(422);

        $this->assertSame(0, SaleReturnDetails::count());
    }

    private function lot(float $purchased, float $remaining, float $conversion = 1): int
    {
        $this->purchaseId ??= (int) DB::table('purchases')->insertGetId([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'PUR-1',
            'purchaseDate' => now()->toDateString(),
            'totalAmount' => 0,
        ]);

        return (int) DB::table('purchase_details')->insertGetId([
            'purchase_id' => $this->purchaseId,
            'stock_id' => $this->stock->id,
            'product_id' => $this->detail->product_id,
            'quantities' => $purchased,
            'conversionQuantity' => $remaining,
            'conversionValue' => $conversion,
            'purchaseUpperLimit' => 0,
            'purchaseLowerLimit' => 0,
            'productDealerPrice' => 0,
            'productPurchasePrice' => 0,
            'conversionPurchasePrice' => 0,
            'conversionPrice' => 0,
            'productSalePrice' => 0,
            'productWholeSalePrice' => 0,
            'totalPrice' => 0,
            'totalPurchasePrice' => 0,
        ]);
    }

    public function test_a_retried_return_restores_stock_once(): void
    {
        $headers = ['Idempotency-Key' => 'return-retry-001'];
        $body = [
            'sale_id' => $this->sale->id,
            'return_date' => now()->toDateString(),
            'sale_detail_id' => [$this->detail->id],
            'return_qty' => [1],
            'return_amount' => [100],
        ];

        $this->actingAs($this->user)->postJson('/api/v1/sales-return', $body, $headers)->assertStatus(200);
        $this->actingAs($this->user)->postJson('/api/v1/sales-return', $body, $headers)->assertStatus(200);

        $this->assertSame(1, SaleReturnDetails::count());
        $this->assertSame(6.0, (float) $this->stock->fresh()->productStock);
    }
}

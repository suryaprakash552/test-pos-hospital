<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home screen's profit figure.
 *
 * It was summed over a join of `sale_details` to `purchase_details` on
 * `stock_id` — one row per *lot*, not per batch. A batch refilled ten times
 * counted every sale from it ten times.
 *
 * Reported from the field as "Profit ₹223.86K" sitting above "Sales ₹102.19K",
 * and confirmed on live data: 2,653 sale lines became 3,739 rows, and the
 * figure came out at 6,441,226 against 4,072,083 of sales.
 */
class SummaryProfitTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $this->stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
        ]);
    }

    /**
     * One more delivery into the same batch. Each is a `purchase_details` row.
     */
    private function addLot(): void
    {
        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'purchaseDate' => now(),
            'totalAmount' => 600,
            'paidAmount' => 600,
            'dueAmount' => 0,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->stock->product_id,
            'stock_id' => $this->stock->id,
            'quantities' => 10,
            'conversionQuantity' => 10,
            'conversionValue' => 1,
            'conversionPurchasePrice' => 60,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /**
     * One sale of one unit: sold at 100, cost 60, so 40 of profit.
     */
    private function sellOne(): void
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'customer_id' => $customer->id,
            'saleDate' => now(),
            'totalAmount' => 100,
            'paidAmount' => 100,
            'dueAmount' => 0,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->stock->product_id,
            'stock_id' => $this->stock->id,
            'quantities' => 1,
            'price' => 100,
            'lossProfit' => 40,
        ]);
    }

    private function profit(): float
    {
        return (float) data_get(
            $this->actingAs($this->owner)->getJson('/api/v1/summary')->json(),
            'data.income',
        );
    }

    public function test_one_sale_from_a_batch_with_one_lot(): void
    {
        $this->addLot();
        $this->sellOne();

        $this->assertSame(40.0, $this->profit());
    }

    /**
     * The bug in one assertion: the sale is unchanged, only the batch's history
     * grew, so the profit must not move.
     */
    public function test_extra_deliveries_into_the_batch_do_not_multiply_profit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->addLot();
        }

        $this->sellOne();

        $this->assertSame(
            40.0,
            $this->profit(),
            'five lots must not report five times the profit',
        );
    }

    public function test_profit_never_exceeds_what_was_sold(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->addLot();
        }

        $this->sellOne();
        $this->sellOne();

        $sales = (float) data_get(
            $this->actingAs($this->owner)->getJson('/api/v1/summary')->json(),
            'data.sales',
        );

        $this->assertLessThanOrEqual($sales, $this->profit(),
            'a shop cannot make more profit than it took in');
    }

    public function test_a_line_sold_below_cost_does_not_subtract(): void
    {
        $this->addLot();

        $customer = Customer::factory()->create(['business_id' => $this->business->id]);
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'customer_id' => $customer->id,
            'saleDate' => now(),
            'totalAmount' => 10,
            'paidAmount' => 10,
            'dueAmount' => 0,
        ]);
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->stock->product_id,
            'stock_id' => $this->stock->id,
            'quantities' => 1,
            'price' => 10,
            'lossProfit' => -50,
        ]);

        $this->assertSame(0.0, $this->profit(),
            'the clamp this figure has always carried is kept');
    }
}

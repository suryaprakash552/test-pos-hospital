<?php

namespace Tests\Feature\Pricing;

use App\Models\Business;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use App\Services\Pricing\CartCalculationException;
use App\Services\Pricing\PurchaseReturnCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The supplier-credit calculator, tested directly.
 *
 * The sibling of `SaleReturnCalculationServiceTest`: 345 lines deciding what a
 * supplier owes back, with no test naming the class until now.
 *
 * Its distinguishing rule is where the stock check comes from. `productStock`
 * is a redundant tally that has drifted badly on live data — thousands of
 * batches hold a negative figure — so returnable stock is measured from the
 * purchase lots, the same ledger a sale consumes from.
 */
class PurchaseReturnCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;
    private PurchaseReturnCalculationService $calculator;

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
            'type' => 'Supplier',
            'name' => 'Test Supplier',
            'due' => 0,
        ]);

        $this->calculator = app(PurchaseReturnCalculationService::class);
        $this->actingAs($this->user);
    }

    /**
     * A purchase of one line at [$cost] each.
     *
     * @return array{0: Purchase, 1: PurchaseDetails}
     */
    private function purchase(float $cost, float $quantity, array $overrides = []): array
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'conversionValue' => 1,
            'productStock' => $quantity,
        ]);

        $total = $cost * $quantity;

        $purchase = Purchase::create(array_merge([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $this->supplier->id,
            'purchaseDate' => now(),
            'totalAmount' => $total,
            'paidAmount' => $total,
            'dueAmount' => 0,
        ], $overrides));

        $detail = PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => $quantity,
            'conversionQuantity' => $quantity,
            'conversionValue' => 1,
            'productPurchasePrice' => $cost,
            'productSalePrice' => $cost * 2,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        return [$purchase, $detail];
    }

    public function test_the_credit_is_priced_from_the_purchase(): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10);

        $result = $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => 4],
        ]);

        $this->assertSame(200.0, $result->totalRefund->toFloat());
    }

    /** A credit clears what is still owed to the supplier before cash moves. */
    public function test_a_credit_settles_an_outstanding_due_first(): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10, overrides: [
            'paidAmount' => 300,
            'dueAmount' => 200,
        ]);

        $result = $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => 6],
        ]);

        $this->assertSame(200.0, $result->supplierDueReduction->toFloat());
        $this->assertSame(100.0, $result->cashRefund->toFloat());
    }

    public function test_a_settled_purchase_credits_entirely_in_cash(): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10);

        $result = $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => 2],
        ]);

        $this->assertSame(0.0, $result->supplierDueReduction->toFloat());
        $this->assertSame(100.0, $result->cashRefund->toFloat());
    }

    public function test_the_purchase_total_is_reduced_by_the_credit(): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10);

        $result = $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => 3],
        ]);

        $this->assertSame(350.0, $result->purchaseTotalAfterReturn->toFloat());
    }

    public function test_returning_more_than_was_bought_is_refused(): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => 11],
        ]);
    }

    /**
     * Stock already sold on cannot be sent back to the supplier.
     *
     * Measured from the lots, not from `productStock` — that tally has drifted
     * negative on thousands of live batches, and gating on it refused
     * legitimate returns with "only -110.67 remain in stock".
     */
    public function test_stock_already_sold_cannot_be_returned(): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10);

        // Eight of the ten have been sold: the lot is down to two.
        DB::table('purchase_details')
            ->where('id', $detail->id)
            ->update(['conversionQuantity' => 2]);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => 5],
        ]);
    }

    /** What is still on the shelf can go back. */
    public function test_stock_still_held_can_be_returned(): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10);

        DB::table('purchase_details')
            ->where('id', $detail->id)
            ->update(['conversionQuantity' => 6]);

        $result = $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => 5],
        ]);

        $this->assertSame(250.0, $result->totalRefund->toFloat());
    }

    public function test_an_empty_return_is_refused(): void
    {
        [$purchase] = $this->purchase(cost: 50, quantity: 10);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($purchase, []);
    }

    /** @dataProvider provideInvalidQuantities */
    public function test_an_invalid_quantity_is_refused(mixed $quantity): void
    {
        [$purchase, $detail] = $this->purchase(cost: 50, quantity: 10);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $detail->id, 'return_qty' => $quantity],
        ]);
    }

    /** @return array<string, array{0: mixed}> */
    public static function provideInvalidQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'not a number' => ['abc'],
            'null' => [null],
        ];
    }

    /** A line from another purchase cannot be returned against this one. */
    public function test_another_purchases_line_is_refused(): void
    {
        [$purchase] = $this->purchase(cost: 50, quantity: 10);
        [, $otherDetail] = $this->purchase(cost: 50, quantity: 10);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($purchase, [
            ['purchase_detail_id' => $otherDetail->id, 'return_qty' => 1],
        ]);
    }
}

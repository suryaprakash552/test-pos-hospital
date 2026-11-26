<?php

namespace Tests\Feature\Pricing;

use App\Models\Business;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use App\Services\Pricing\CartCalculationException;
use App\Services\Pricing\SaleReturnCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The refund calculator, tested directly rather than through the endpoint.
 *
 * 320 lines deciding how much money leaves the business, and until now no test
 * named this class — every assertion about it went through one HTTP test, so a
 * change in the controller and a change in the arithmetic were indistinguishable
 * when something broke.
 *
 * The rules it enforces, each of which has been a live defect:
 *   - the refund is priced from the sale, never from the request
 *   - it rounds *down*, so the customer is never handed back more than they paid
 *   - the lines are rescaled to sum to that rounded total
 *   - a refund settles an outstanding due before any cash moves
 */
class SaleReturnCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private SaleReturnCalculationService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->calculator = app(SaleReturnCalculationService::class);
        $this->actingAs($this->user);
    }

    /**
     * A settled sale of one line.
     *
     * @return array{0: Sale, 1: SaleDetails}
     */
    private function sale(float $price, float $quantity, array $overrides = []): array
    {
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
        ]);

        $total = $price * $quantity;

        $sale = Sale::create(array_merge([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'invoiceNumber' => 'CALC-'.uniqid(),
            'saleDate' => now(),
            'totalAmount' => $total,
            'actual_total_amount' => $total,
            'paidAmount' => $total,
            'dueAmount' => 0,
            'lossProfit' => 0,
        ], $overrides));

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'price' => $price,
            'quantities' => $quantity,
            'lossProfit' => 0,
        ]);

        return [$sale, $detail];
    }

    public function test_the_refund_is_priced_from_the_sale(): void
    {
        [$sale, $detail] = $this->sale(price: 100, quantity: 4);

        $result = $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => 2],
        ]);

        $this->assertSame(200.0, $result->totalRefund->toFloat());
    }

    /** ₹32.81 refunds ₹32: the 19 paise was never paid. */
    public function test_a_refund_rounds_down(): void
    {
        [$sale, $detail] = $this->sale(price: 32.81, quantity: 1, overrides: [
            'totalAmount' => 33,
            'actual_total_amount' => 32.81,
            'paidAmount' => 33,
        ]);

        $result = $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => 1],
        ]);

        $this->assertSame(32.0, $result->totalRefund->toFloat());
    }

    /**
     * The lines are what the invoice and the reports print, so they have to add
     * up to the total that was actually refunded.
     */
    public function test_the_lines_sum_to_the_rounded_total(): void
    {
        [$sale, $detail] = $this->sale(price: 32.81, quantity: 1, overrides: [
            'totalAmount' => 33,
            'actual_total_amount' => 32.81,
            'paidAmount' => 33,
        ]);

        $result = $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => 1],
        ]);

        $lineSum = array_reduce(
            $result->lines,
            fn ($carry, $line) => $carry + $line->refund->toFloat(),
            0.0,
        );

        $this->assertEqualsWithDelta($result->totalRefund->toFloat(), $lineSum, 0.001);
    }

    /** A refund clears what is still owed before any cash is handed over. */
    public function test_a_refund_settles_an_outstanding_due_first(): void
    {
        [$sale, $detail] = $this->sale(price: 100, quantity: 4, overrides: [
            'paidAmount' => 250,
            'dueAmount' => 150,
        ]);

        $result = $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => 2],
        ]);

        $this->assertSame(150.0, $result->customerDueReduction->toFloat());
        $this->assertSame(50.0, $result->cashRefund->toFloat());
        $this->assertSame(200.0, $result->salePaidAfterReturn->toFloat());
    }

    /** On a settled sale the whole refund is cash. */
    public function test_a_settled_sale_refunds_entirely_in_cash(): void
    {
        [$sale, $detail] = $this->sale(price: 100, quantity: 4);

        $result = $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => 1],
        ]);

        $this->assertSame(0.0, $result->customerDueReduction->toFloat());
        $this->assertSame(100.0, $result->cashRefund->toFloat());
    }

    public function test_returning_more_than_was_sold_is_refused(): void
    {
        [$sale, $detail] = $this->sale(price: 100, quantity: 4);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => 5],
        ]);
    }

    public function test_an_empty_return_is_refused(): void
    {
        [$sale] = $this->sale(price: 100, quantity: 4);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($sale, []);
    }

    /** @dataProvider provideInvalidQuantities */
    public function test_an_invalid_quantity_is_refused(mixed $quantity): void
    {
        [$sale, $detail] = $this->sale(price: 100, quantity: 4);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => $quantity],
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
            'empty' => [''],
        ];
    }

    /** A line belonging to a different sale cannot be returned against this one. */
    public function test_another_sales_line_is_refused(): void
    {
        [$sale] = $this->sale(price: 100, quantity: 4);
        [, $otherDetail] = $this->sale(price: 100, quantity: 4);

        $this->expectException(CartCalculationException::class);

        $this->calculator->calculate($sale, [
            ['sale_detail_id' => $otherDetail->id, 'return_qty' => 1],
        ]);
    }

    /** The quantity left on the line after the return comes back with it. */
    public function test_the_remaining_quantity_is_reported(): void
    {
        [$sale, $detail] = $this->sale(price: 100, quantity: 4);

        $result = $this->calculator->calculate($sale, [
            ['sale_detail_id' => $detail->id, 'return_qty' => 3],
        ]);

        $this->assertSame(1.0, $result->lines[0]->remainingQuantity);
    }
}

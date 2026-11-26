<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use App\Models\Vat;
use App\Services\Pricing\CartCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ten GST/discount combinations, pinned against the current formula.
 *
 * This documents what the application actually computes today. It is deliberately
 * a characterisation test: the open question is whether an order-level discount
 * should reduce the taxable base, and that is a tax-treatment decision rather than
 * a coding one. See `test_an_order_discount_does_not_reduce_gst` for the case where
 * the current behaviour and the commonly expected one diverge.
 */
class GstDiscountScenarioTest extends TestCase
{
    use RefreshDatabase;

    private int $businessId;
    private Stock $taxed;
    private Stock $untaxed;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->businessId = (int) $business->id;
        User::factory()->create(['business_id' => $business->id, 'role' => 'shop-owner']);

        $vat = Vat::create([
            'business_id' => $business->id,
            'name' => 'GST 18',
            'rate' => 18,
        ]);

        $this->taxed = $this->stockFor($business->id, 'Taxed', 100, $vat->id, 'exclusive');
        $this->untaxed = $this->stockFor($business->id, 'Untaxed', 100, null, 'exclusive');
    }

    private function stockFor(int $businessId, string $name, float $price, ?int $vatId, string $vatType): Stock
    {
        $product = Product::create([
            'business_id' => $businessId,
            'productName' => $name,
            'productSalePrice' => $price,
            'productPurchasePrice' => 50,
            'vat_id' => $vatId,
            'vat_type' => $vatType,
        ]);

        return Stock::create([
            'business_id' => $businessId,
            'product_id' => $product->id,
            'batch_no' => $name . '-1',
            'productStock' => 1000,
            'productSalePrice' => $price,
            'productPurchasePrice' => 50,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $options
     */
    private function calculate(array $lines, array $options = []): \App\Services\Pricing\CalculatedCart
    {
        return app(CartCalculationService::class)->calculate(
            $this->businessId,
            $lines,
            $options,
            null,
            lockStock: false,
        );
    }

    private function line(Stock $stock, float $qty, array $extra = []): array
    {
        return ['stock_id' => $stock->id, 'quantities' => $qty] + $extra;
    }

    public function test_no_discount_and_no_gst(): void
    {
        $cart = $this->calculate([$this->line($this->untaxed, 2)]);

        $this->assertSame(200.0, $cart->subtotal->toFloat());
        $this->assertSame(0.0, $cart->taxTotal->toFloat());
        $this->assertSame(200.0, $cart->grandTotal->toFloat());
    }

    public function test_gst_only(): void
    {
        $cart = $this->calculate([$this->line($this->taxed, 2)]);

        $this->assertSame(200.0, $cart->subtotal->toFloat());
        $this->assertSame(36.0, $cart->taxTotal->toFloat());
        $this->assertSame(236.0, $cart->grandTotal->toFloat());
    }

    public function test_flat_order_discount_only(): void
    {
        $cart = $this->calculate(
            [$this->line($this->untaxed, 2)],
            ['discount_amount' => 50, 'discount_type' => 'flat'],
        );

        $this->assertSame(50.0, $cart->orderDiscount->toFloat());
        $this->assertSame(150.0, $cart->grandTotal->toFloat());
    }

    public function test_percentage_order_discount_only(): void
    {
        $cart = $this->calculate(
            [$this->line($this->untaxed, 2)],
            ['discount_percent' => 10, 'discount_type' => 'percent'],
        );

        $this->assertSame(20.0, $cart->orderDiscount->toFloat());
        $this->assertSame(180.0, $cart->grandTotal->toFloat());
    }

    public function test_a_row_level_flat_discount_reduces_the_taxable_line(): void
    {
        $cart = $this->calculate([$this->line($this->taxed, 2, ['discount_flat' => 40])]);

        // Row discount comes off before tax, so GST is charged on 160.
        $this->assertSame(160.0, $cart->subtotal->toFloat());
        $this->assertSame(28.8, $cart->taxTotal->toFloat());
        $this->assertSame(188.8, $cart->grandTotal->toFloat());
    }

    public function test_a_row_level_percent_discount_reduces_the_taxable_line(): void
    {
        $cart = $this->calculate([$this->line($this->taxed, 2, ['discount_percent' => 20])]);

        $this->assertSame(160.0, $cart->subtotal->toFloat());
        $this->assertSame(28.8, $cart->taxTotal->toFloat());
    }

    public function test_multiple_products_with_different_quantities(): void
    {
        $cart = $this->calculate([
            $this->line($this->taxed, 3),
            $this->line($this->untaxed, 2),
        ]);

        $this->assertSame(500.0, $cart->subtotal->toFloat());
        $this->assertSame(54.0, $cart->taxTotal->toFloat(), 'Only the taxed line carries GST.');
        $this->assertSame(554.0, $cart->grandTotal->toFloat());
    }

    /**
     * Where the discount was typed must not change the tax.
     *
     * A row discount always reduced GST, because it sits inside the line. An
     * order-level discount did not: tax was summed per line before the order
     * discount was applied. The same goods with the same 40 off came to 188.80 or
     * 196.00 depending purely on which box the cashier used.
     */
    public function test_an_order_discount_reduces_gst_exactly_as_a_row_discount_does(): void
    {
        $viaRow = $this->calculate([$this->line($this->taxed, 2, ['discount_flat' => 40])]);
        $viaOrder = $this->calculate(
            [$this->line($this->taxed, 2)],
            ['discount_amount' => 40, 'discount_type' => 'flat'],
        );

        $this->assertSame(28.8, $viaRow->taxTotal->toFloat());
        $this->assertSame(28.8, $viaOrder->taxTotal->toFloat());

        $this->assertSame(
            $viaRow->grandTotal->toFloat(),
            $viaOrder->grandTotal->toFloat(),
            'Same goods and same money off must bill the same, however it was entered.',
        );

        $this->assertSame(188.8, $viaOrder->grandTotal->toFloat());
    }

    /**
     * A percentage order discount reaches the taxable base too.
     */
    public function test_a_percentage_order_discount_reduces_gst(): void
    {
        $cart = $this->calculate(
            [$this->line($this->taxed, 2)],
            ['discount_percent' => 20, 'discount_type' => 'percent'],
        );

        $this->assertSame(40.0, $cart->orderDiscount->toFloat());
        $this->assertSame(28.8, $cart->taxTotal->toFloat());
        $this->assertSame(188.8, $cart->grandTotal->toFloat());
    }

    /**
     * A mixed basket keeps each line on its own rate.
     *
     * The reduction is apportioned pro-rata on net, so a discount cannot be
     * steered onto the taxed line to shrink the GST due.
     */
    public function test_an_order_discount_is_apportioned_across_mixed_rates(): void
    {
        $cart = $this->calculate(
            [$this->line($this->taxed, 1), $this->line($this->untaxed, 1)],
            ['discount_amount' => 100, 'discount_type' => 'flat'],
        );

        // 100 off a 200 subtotal: 50 to each line. Only the taxed line has a rate.
        $this->assertSame(9.0, $cart->taxTotal->toFloat(), 'GST @18% on 50, not on 100 or 150.');
        $this->assertSame(109.0, $cart->grandTotal->toFloat());
    }

    /**
     * The apportioned shares must sum to exactly the discount.
     *
     * Three equal lines against 10.00 is the classic case where independently
     * rounded thirds lose or invent a paisa.
     */
    public function test_apportioning_does_not_lose_a_paisa(): void
    {
        $cart = $this->calculate(
            [
                $this->line($this->untaxed, 1),
                $this->line($this->untaxed, 1),
                $this->line($this->untaxed, 1),
            ],
            ['discount_amount' => 10, 'discount_type' => 'flat'],
        );

        $this->assertSame(300.0, $cart->subtotal->toFloat());
        $this->assertSame(10.0, $cart->orderDiscount->toFloat());
        $this->assertSame(290.0, $cart->grandTotal->toFloat());
    }

    public function test_gst_with_shipping_and_rounding(): void
    {
        $cart = $this->calculate(
            [$this->line($this->taxed, 1)],
            ['shipping_charge' => 10.4, 'rounding_option' => 'nearest_whole_number'],
        );

        $this->assertSame(18.0, $cart->taxTotal->toFloat());
        $this->assertSame(128.4, $cart->actualTotal->toFloat());
        $this->assertSame(128.0, $cart->grandTotal->toFloat());
        $this->assertSame(-0.4, $cart->roundingAmount->toFloat());
    }

    public function test_an_order_discount_larger_than_the_subtotal_is_refused(): void
    {
        $this->expectException(\App\Services\Pricing\CartCalculationException::class);

        $this->calculate(
            [$this->line($this->untaxed, 1)],
            ['discount_amount' => 500, 'discount_type' => 'flat'],
        );
    }
}

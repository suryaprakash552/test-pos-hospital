<?php

namespace Tests\Feature\Pricing;

use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Vat;
use App\Services\Pricing\CartCalculationService;
use App\Services\Pricing\PurchaseCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A row discount has to survive the trip to the server.
 *
 * Reported from the shop: a purchase cart totalling 3,941.32 billed 4,253.82,
 * and the GST differed too. Two faults met.
 *
 * The cart's payload carried no discount field at all, so the server priced
 * every line at its gross — and then taxed the gross, so the tax relief on the
 * discount went with it.
 *
 * And where a discount *was* sent, both services read only `discount_percent`
 * and `discount_flat`. The app records a plain figure in `discount_amount`
 * when neither box was used, and honours it locally, so the two disagreed
 * about the same cart.
 */
class RowDiscountCarriesThroughTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
    }

    private function stock(float $price, float $rate, string $vatType = 'exclusive'): Stock
    {
        $vat = Vat::factory()->create([
            'business_id' => $this->business->id,
            'rate' => $rate,
        ]);

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'vat_id' => $vat->id,
            'vat_type' => $vatType,
            'productSalePrice' => $price,
            'conversionValue' => 1,
        ]);

        return Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productSalePrice' => $price,
            'productPurchasePrice' => $price,
            'conversionValue' => 1,
        ]);
    }

    /**
     * The reported line: 2,550 gross, 300 off, 5% GST.
     *
     *   net 2,250, tax 112.50, payable 2,362.50 — as the cart showed.
     * Priced at gross it would be 2,550 + 127.50 = 2,677.50.
     */
    public function test_a_sale_line_honours_a_discount_given_as_an_amount(): void
    {
        $stock = $this->stock(price: 2550, rate: 5);

        $cart = app(CartCalculationService::class)->calculate(
            $this->business->id,
            [[
                'stock_id' => $stock->id,
                'quantities' => 1,
                'discount_amount' => 300,
            ]],
        );

        $this->assertSame(2250.0, $cart->lines[0]->net->toFloat());
        $this->assertSame(112.50, $cart->lines[0]->tax->toFloat(),
            'the tax follows the discounted figure, not the gross');
        $this->assertSame(2362.50, $cart->grandTotal->toFloat());
    }

    public function test_a_purchase_line_honours_a_discount_given_as_an_amount(): void
    {
        $stock = $this->stock(price: 2550, rate: 5);

        $cart = app(PurchaseCalculationService::class)->calculate(
            $this->business->id,
            [[
                'product_id' => $stock->product_id,
                'stock_id' => $stock->id,
                'quantities' => 1,
                'productPurchasePrice' => 2550,
                'discount_amount' => 300,
            ]],
        );

        $this->assertSame(2250.0, $cart->lines[0]->net->toFloat());
        $this->assertSame(112.50, $cart->lines[0]->tax->toFloat());
    }

    /** An explicit percentage still wins over a plain amount. */
    public function test_a_percentage_takes_precedence(): void
    {
        $stock = $this->stock(price: 1000, rate: 0);

        $cart = app(CartCalculationService::class)->calculate(
            $this->business->id,
            [[
                'stock_id' => $stock->id,
                'quantities' => 1,
                'discount_percent' => 10,
                'discount_amount' => 999,
            ]],
        );

        $this->assertSame(100.0, $cart->lines[0]->discount->toFloat());
    }

    public function test_a_flat_amount_takes_precedence_over_the_fallback(): void
    {
        $stock = $this->stock(price: 1000, rate: 0);

        $cart = app(CartCalculationService::class)->calculate(
            $this->business->id,
            [[
                'stock_id' => $stock->id,
                'quantities' => 1,
                'discount_flat' => 50,
                'discount_amount' => 999,
            ]],
        );

        $this->assertSame(50.0, $cart->lines[0]->discount->toFloat());
    }

    public function test_a_discount_cannot_exceed_the_line(): void
    {
        $stock = $this->stock(price: 100, rate: 0);

        $cart = app(CartCalculationService::class)->calculate(
            $this->business->id,
            [[
                'stock_id' => $stock->id,
                'quantities' => 1,
                'discount_amount' => 1000,
            ]],
        );

        $this->assertSame(100.0, $cart->lines[0]->discount->toFloat());
        $this->assertSame(0.0, $cart->grandTotal->toFloat());
    }

    /**
     * The reported billing screen: a 23,000 cart with 2,000 off the rows and a
     * 675 overall discount showed a total of 22,980.19 — exactly 2,000 out,
     * because the row discount never reached the server and the subtotal it
     * worked from was the gross.
     */
    public function test_a_row_discount_and_an_overall_discount_both_apply(): void
    {
        $stock = $this->stock(price: 23000, rate: 0);

        $cart = app(PurchaseCalculationService::class)->calculate(
            $this->business->id,
            [[
                'product_id' => $stock->product_id,
                'stock_id' => $stock->id,
                'quantities' => 1,
                'productPurchasePrice' => 23000,
                'discount_amount' => 2000,
            ]],
            ['discount_amount' => 675, 'discount_type' => 'flat'],
        );

        $this->assertSame(21000.0, $cart->subtotal->toFloat(),
            'the subtotal is net of the row discount');
        $this->assertSame(20325.0, $cart->grandTotal->toFloat(),
            '23,000 less 2,000 less 675');
    }

    public function test_tax_is_charged_on_what_is_actually_billed(): void
    {
        $stock = $this->stock(price: 23000, rate: 5);

        $cart = app(PurchaseCalculationService::class)->calculate(
            $this->business->id,
            [[
                'product_id' => $stock->product_id,
                'stock_id' => $stock->id,
                'quantities' => 1,
                'productPurchasePrice' => 23000,
                'discount_amount' => 2000,
            ]],
        );

        // 5% of 21,000, not of 23,000.
        $this->assertSame(1050.0, $cart->taxTotal->toFloat());
        $this->assertSame(22050.0, $cart->grandTotal->toFloat());
    }

    public function test_purchase_rate_is_exclusive_even_when_future_sales_are_inclusive(): void
    {
        $stock = $this->stock(price: 118, rate: 18, vatType: 'inclusive');

        $cart = app(PurchaseCalculationService::class)->calculate(
            $this->business->id,
            [[
                'product_id' => $stock->product_id,
                'stock_id' => $stock->id,
                'quantities' => 1,
                'productPurchasePrice' => 118,
                'productSalePrice' => 150,
            ]],
        );

        $this->assertFalse($cart->lines[0]->taxInclusive);
        $this->assertSame(21.24, $cart->lines[0]->tax->toFloat());
        $this->assertSame(139.24, $cart->grandTotal->toFloat());
    }

    public function test_an_overall_percentage_discount_applies_to_the_net(): void
    {
        $stock = $this->stock(price: 1000, rate: 0);

        $cart = app(PurchaseCalculationService::class)->calculate(
            $this->business->id,
            [[
                'product_id' => $stock->product_id,
                'stock_id' => $stock->id,
                'quantities' => 1,
                'productPurchasePrice' => 1000,
                'discount_amount' => 200,
            ]],
            ['discount_percent' => 10, 'discount_type' => 'percent'],
        );

        // 10% of the 800 that is actually owed, not of the 1,000 gross.
        $this->assertSame(720.0, $cart->grandTotal->toFloat());
    }
}

<?php

namespace Tests\Feature\Pricing;

use App\Models\Business;
use App\Models\Customer;
use App\Models\GstConfigurationHistory;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Vat;
use App\Services\Pricing\CartCalculationException;
use App\Services\Pricing\CartCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The server is the only thing allowed to decide what a sale costs. These pin the
 * arithmetic, and pin the refusals that stop a client naming its own figures.
 */
class CartCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private CartCalculationService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->calculator = app(CartCalculationService::class);
    }

    /**
     * A batch of $stock at $price each, with an optional tax rate.
     */
    private function stock(
        float $price,
        float $rate = 0,
        string $vatType = 'exclusive',
        float $purchase = 60,
        float $conversionValue = 1,
    ): Stock {
        $vat = $rate > 0
            ? Vat::factory()->create(['business_id' => $this->business->id, 'rate' => $rate])
            : null;

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'vat_id' => $vat?->id,
            'vat_type' => $vatType,
            'productSalePrice' => $price,
            'conversionValue' => $conversionValue,
        ]);

        return Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productSalePrice' => $price,
            'productPurchasePrice' => $purchase,
            'conversionValue' => $conversionValue,
        ]);
    }

    private function calculate(array $lines, array $options = [], ?Customer $customer = null)
    {
        return $this->calculator->calculate($this->business->id, $lines, $options, $customer);
    }

    public function test_cart_preview_rejects_expired_stock_but_accepts_expiry_today(): void
    {
        $stock = $this->stock(100);
        $stock->update(['expire_date' => today()->toDateString()]);
        $lines = [['stock_id' => $stock->id, 'quantities' => 1]];
        $this->assertCount(1, $this->calculate($lines)->lines);
        $stock->update(['expire_date' => today()->subDay()->toDateString()]);
        try {
            $this->calculate($lines);
            $this->fail('Expired stock must not receive a payable cart preview.');
        } catch (CartCalculationException $exception) {
            $this->assertSame('expired_stock', $exception->reason);
            $this->assertSame([$stock->id], $exception->context['stock_ids']);
        }
    }

    public function test_saved_line_tax_snapshot_survives_product_tax_change(): void
    {
        $stock = $this->stock(100, 5, 'inclusive');
        $lines = [['stock_id' => $stock->id, 'quantities' => 1]];
        $posted = $this->calculate($lines)->lines[0]->toSaleDetail(1);
        $stock->product->vat->update(['rate' => 12]);
        $stock->product->update(['vat_type' => 'exclusive']);
        $future = $this->calculate($lines)->lines[0]->toSaleDetail(2);
        $this->assertSame(5.0, $posted['tax_rate_snapshot']);
        $this->assertTrue($posted['tax_inclusive_snapshot']);
        $this->assertSame(12.0, $future['tax_rate_snapshot']);
        $this->assertFalse($future['tax_inclusive_snapshot']);
    }

    public function test_unregistered_gst_configuration_stops_tax_on_new_sales(): void
    {
        $this->business->update(['gst_registered' => false]);
        $stock = $this->stock(100, 5, 'exclusive');

        $line = $this->calculate([
            ['stock_id' => $stock->id, 'quantities' => 1],
        ])->lines[0];

        $this->assertSame(0.0, $line->tax->toFloat());
        $this->assertFalse($line->taxInclusive);
        $this->assertSame(100.0, $line->payable->toFloat());
    }

    public function test_registered_sales_follow_the_inventory_tax_method(): void
    {
        $this->business->update([
            'gst_registered' => true,
            'default_vat_type' => 'exclusive',
        ]);
        $stock = $this->stock(105, 5, 'inclusive');

        $line = $this->calculate([
            ['stock_id' => $stock->id, 'quantities' => 1],
        ])->lines[0];

        $this->assertTrue($line->taxInclusive);
        $this->assertSame(5.0, $line->tax->toFloat());
        $this->assertSame(105.0, $line->payable->toFloat());
    }

    public function test_scheduled_default_method_does_not_start_before_its_date(): void
    {
        $this->business->update([
            'gst_registered' => true,
            'default_vat_type' => 'exclusive',
        ]);
        GstConfigurationHistory::create([
            'business_id' => $this->business->id,
            'configurable_type' => Business::class,
            'configurable_id' => $this->business->id,
            'field' => 'default_vat_type',
            'old_value' => 'inclusive',
            'new_value' => 'exclusive',
            'effective_from' => '2026-10-01',
        ]);

        $stock = $this->stock(105, 5, 'exclusive');
        // Remove the item override so this line follows the business default.
        // The production schema keeps this legacy column non-null. An empty
        // value means no item override and exercises the business default.
        $stock->product->update(['vat_type' => '']);

        $before = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['transaction_date' => '2026-09-30'],
        )->lines[0];
        $after = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['transaction_date' => '2026-10-01'],
        )->lines[0];

        $this->assertTrue($before->taxInclusive);
        $this->assertSame(105.0, $before->payable->toFloat());
        $this->assertFalse($after->taxInclusive);
        $this->assertSame(110.25, $after->payable->toFloat());
    }

    public function test_it_prices_a_line_from_the_batch_not_the_request(): void
    {
        $stock = $this->stock(price: 100);

        $cart = $this->calculate([
            // The client claims each unit costs 1. It does not.
            ['stock_id' => $stock->id, 'quantities' => 2, 'price' => 1, 'conversion_price' => 1],
        ]);

        $this->assertSame(100.0, $cart->lines[0]->unitPrice->toFloat());
        $this->assertSame(200.0, $cart->grandTotal->toFloat());
    }

    public function test_a_missing_sale_price_cannot_fall_back_to_purchase_cost(): void
    {
        $stock = $this->stock(price: 0, purchase: 60);

        try {
            $this->calculate([['stock_id' => $stock->id, 'quantities' => 1]]);
            $this->fail('A zero-priced inventory batch must not enter a sale.');
        } catch (CartCalculationException $exception) {
            $this->assertSame('missing_sale_price', $exception->reason);
            $this->assertSame($stock->id, $exception->context['stock_id']);
        }
    }

    public function test_it_ignores_a_client_supplied_total(): void
    {
        $stock = $this->stock(price: 250);

        $cart = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 4]],
            ['totalAmount' => 1, 'grand_total' => 1, 'vat_amount' => 999],
        );

        $this->assertSame(1000.0, $cart->grandTotal->toFloat());
        $this->assertSame(0.0, $cart->taxTotal->toFloat());
    }

    public function test_exclusive_tax_is_added_on_top(): void
    {
        $stock = $this->stock(price: 100, rate: 18);

        $cart = $this->calculate([['stock_id' => $stock->id, 'quantities' => 1]]);

        $this->assertSame(100.0, $cart->subtotal->toFloat());
        $this->assertSame(18.0, $cart->taxTotal->toFloat());
        $this->assertSame(118.0, $cart->grandTotal->toFloat());
    }

    public function test_inclusive_tax_is_carved_out_of_the_price(): void
    {
        $stock = $this->stock(price: 118, rate: 18, vatType: 'inclusive');

        $cart = $this->calculate([['stock_id' => $stock->id, 'quantities' => 1]]);

        // The customer pays 118 either way; 18 of it is tax.
        $this->assertSame(118.0, $cart->grandTotal->toFloat());
        $this->assertSame(18.0, $cart->taxTotal->toFloat());
    }

    public function test_a_percentage_line_discount_reduces_the_line(): void
    {
        $stock = $this->stock(price: 200);

        $cart = $this->calculate([
            ['stock_id' => $stock->id, 'quantities' => 1, 'discount_percent' => 10],
        ]);

        $this->assertSame(20.0, $cart->lines[0]->discount->toFloat());
        $this->assertSame(180.0, $cart->grandTotal->toFloat());
    }

    public function test_a_line_discount_cannot_exceed_the_line(): void
    {
        $stock = $this->stock(price: 50);

        $cart = $this->calculate([
            ['stock_id' => $stock->id, 'quantities' => 1, 'discount_flat' => 1_000_000],
        ]);

        // Capped at the line, never negative.
        $this->assertSame(50.0, $cart->lines[0]->discount->toFloat());
        $this->assertSame(0.0, $cart->grandTotal->toFloat());
        $this->assertFalse($cart->grandTotal->isNegative());
    }

    public function test_an_order_discount_beyond_the_subtotal_is_refused(): void
    {
        $stock = $this->stock(price: 100);

        $this->expectException(CartCalculationException::class);
        $this->expectExceptionMessage('cannot be greater than the order subtotal');

        $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['discount_amount' => 1_000_000],
        );
    }

    public function test_an_absurd_discount_percentage_is_refused(): void
    {
        $stock = $this->stock(price: 100);

        $this->expectException(CartCalculationException::class);
        $this->expectExceptionMessage('cannot exceed 100%');

        $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['discount_percent' => 9999, 'discount_type' => 'percent'],
        );
    }

    public function test_a_negative_discount_is_refused(): void
    {
        $stock = $this->stock(price: 100);

        $this->expectException(CartCalculationException::class);
        $this->expectExceptionMessage('cannot be negative');

        $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['discount_amount' => -500],
        );
    }

    /** @return array<string, array{mixed}> */
    public static function provideInvalidQuantities(): array
    {
        return [
            'zero' => [0],
            'negative' => [-3],
            'not a number' => ['abc'],
            'empty' => [''],
            'null' => [null],
        ];
    }

    /** @dataProvider provideInvalidQuantities */
    public function test_invalid_quantities_are_refused(mixed $quantity): void
    {
        $stock = $this->stock(price: 100);

        $this->expectException(CartCalculationException::class);

        $this->calculate([['stock_id' => $stock->id, 'quantities' => $quantity]]);
    }

    public function test_an_absurdly_large_quantity_is_refused(): void
    {
        $stock = $this->stock(price: 100);

        $this->expectException(CartCalculationException::class);
        $this->expectExceptionMessage('exceeds the maximum');

        $this->calculate([['stock_id' => $stock->id, 'quantities' => 999_999_999]]);
    }

    public function test_another_tenants_stock_is_not_priceable(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);
        $foreignStock = Stock::factory()->create([
            'business_id' => $otherBusiness->id,
            'product_id' => $foreignProduct->id,
        ]);

        $this->expectException(CartCalculationException::class);
        $this->expectExceptionMessage('not available for this business');

        $this->calculate([['stock_id' => $foreignStock->id, 'quantities' => 1]]);
    }

    public function test_dealer_customers_get_the_dealer_price(): void
    {
        $stock = $this->stock(price: 100);
        $stock->update(['productDealerPrice' => 80]);

        $dealer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'type' => 'Dealer',
        ]);

        $cart = $this->calculate([['stock_id' => $stock->id, 'quantities' => 1]], [], $dealer);

        $this->assertSame(80.0, $cart->grandTotal->toFloat());
    }

    public function test_due_and_change_are_derived_from_what_was_tendered(): void
    {
        $stock = $this->stock(price: 100);

        $partiallyPaid = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            // Client also claims it owes nothing; the server disagrees.
            ['paid_amount' => 40, 'dueAmount' => 0, 'change_amount' => 500],
        );

        $this->assertSame(40.0, $partiallyPaid->paid->toFloat());
        $this->assertSame(60.0, $partiallyPaid->due->toFloat());
        $this->assertSame(0.0, $partiallyPaid->change->toFloat());

        $overpaid = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['paid_amount' => 150],
        );

        $this->assertSame(100.0, $overpaid->paid->toFloat());
        $this->assertSame(0.0, $overpaid->due->toFloat());
        $this->assertSame(50.0, $overpaid->change->toFloat());
    }

    public function test_rounding_is_applied_consistently(): void
    {
        $stock = $this->stock(price: 100.40);

        $cart = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['rounding_option' => 'nearest_whole_number'],
        );

        $this->assertSame(100.40, $cart->actualTotal->toFloat());
        $this->assertSame(100.0, $cart->grandTotal->toFloat());
        $this->assertSame(-0.40, $cart->roundingAmount->toFloat());
    }

    /**
     * `round_down` never charges more than the bill came to.
     *
     * A ₹72.74 bill rounding up to ₹73 takes 26 paise the customer never agreed
     * to. The counter wants the shop to absorb the fraction instead, so this
     * floors rather than rounding to nearest.
     */
    public function test_rounding_down_never_charges_more_than_the_bill(): void
    {
        $stock = $this->stock(price: 72.74);

        $cart = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['rounding_option' => 'round_down'],
        );

        $this->assertSame(72.74, $cart->actualTotal->toFloat());
        $this->assertSame(72.0, $cart->grandTotal->toFloat());
        $this->assertSame(-0.74, $cart->roundingAmount->toFloat());
    }

    /** A whole-rupee bill is left alone rather than dropped a rupee. */
    public function test_rounding_down_leaves_an_exact_amount_untouched(): void
    {
        $stock = $this->stock(price: 72.00);

        $cart = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['rounding_option' => 'round_down'],
        );

        $this->assertSame(72.0, $cart->grandTotal->toFloat());
        $this->assertSame(0.0, $cart->roundingAmount->toFloat());
    }

    public function test_an_unknown_rounding_option_rounds_nothing(): void
    {
        $stock = $this->stock(price: 100.40);

        $cart = $this->calculate(
            [['stock_id' => $stock->id, 'quantities' => 1]],
            ['rounding_option' => 'round_to_whatever_i_like'],
        );

        $this->assertSame(100.40, $cart->grandTotal->toFloat());
        $this->assertSame('none', $cart->roundingOption);
    }

    public function test_fractional_quantities_are_priced_exactly(): void
    {
        $stock = $this->stock(price: 0.10);

        // The classic float trap: 0.1 x 3 is not 0.30000000000000004 here.
        $cart = $this->calculate([['stock_id' => $stock->id, 'quantities' => 3]]);

        $this->assertSame(0.30, $cart->grandTotal->toFloat());
    }

    public function test_many_small_lines_sum_without_drift(): void
    {
        $stock = $this->stock(price: 0.07);

        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = ['stock_id' => $stock->id, 'quantities' => 1];
        }

        $cart = $this->calculate($lines);

        $this->assertSame(7.00, $cart->grandTotal->toFloat());
    }

    /**
     * Reported from the field, and confirmed against the live database: a strip
     * of LEVIROZ-500 sells at 32.54 with 10 tablets to a strip. A bill for 1490
     * tablets stored a total of 48485 - the strip price times a tablet count -
     * instead of roughly the 4842 the till itself displayed. `quantities` is a
     * lower-unit count once a product has a second unit; the resolved price has
     * to come down to match it.
     *
     * 4848.46, not the 4842.50 on screen: 1490 tablets is 149 whole strips at
     * 32.54, and the till's figure came from rounding 3.254 down to 3.25 before
     * multiplying. Both sides now divide once at full precision, so they agree.
     */
    public function test_a_converted_unit_prices_from_the_lower_unit_not_the_strip(): void
    {
        $stock = $this->stock(price: 32.54, conversionValue: 10);

        $cart = $this->calculate([
            ['stock_id' => $stock->id, 'quantities' => 1490],
        ]);

        $this->assertSame(4848.46, $cart->grandTotal->toFloat());
        $this->assertSame(149 * 32.54, $cart->grandTotal->toFloat());
        $this->assertSame(3.25, $cart->lines[0]->unitPrice->toFloat());
    }

    /**
     * 50 over 12 units is 4.1666..., and a pack of 12 must still come to 50 -
     * not the 50.04 that rounding the unit price first and multiplying it back
     * up produces.
     */
    public function test_a_whole_pack_costs_exactly_the_pack_price(): void
    {
        $stock = $this->stock(price: 50, conversionValue: 12);

        $cart = $this->calculate([['stock_id' => $stock->id, 'quantities' => 12]]);

        $this->assertSame(50.0, $cart->grandTotal->toFloat());
        // The printed unit price is still the rounded per-unit figure.
        $this->assertSame(4.17, $cart->lines[0]->unitPrice->toFloat());
    }

    /**
     * A batch created by a purchase used to reach here with no conversion of
     * its own, because the purchase wrote the figure to the lot and not to the
     * batch. Treating that as "one" prices a tablet at the price of a strip —
     * the same overcharge, arriving by a different door.
     */
    public function test_a_batch_missing_its_conversion_falls_back_to_the_product(): void
    {
        $stock = $this->stock(price: 32.54, conversionValue: 10);
        $stock->update(['conversionValue' => null]);

        $cart = $this->calculate([
            ['stock_id' => $stock->id, 'quantities' => 10],
        ]);

        $this->assertSame(32.54, $cart->grandTotal->toFloat(),
            'ten tablets is one strip, not ten strips');
    }

    public function test_a_product_with_no_second_unit_is_unaffected_by_conversion(): void
    {
        $stock = $this->stock(price: 50, conversionValue: 1);

        $cart = $this->calculate([['stock_id' => $stock->id, 'quantities' => 3]]);

        $this->assertSame(150.0, $cart->grandTotal->toFloat());
    }

    public function test_profit_is_measured_against_cost_not_the_client_figure(): void
    {
        $stock = $this->stock(price: 100, purchase: 60);

        $cart = $this->calculate([
            // Client claims an enormous profit.
            ['stock_id' => $stock->id, 'quantities' => 2, 'lossProfit' => 999999],
        ]);

        $this->assertSame(80.0, $cart->totalProfit->toFloat());
    }
}

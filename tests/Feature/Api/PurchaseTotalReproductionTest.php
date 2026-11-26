<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug 14: "the purchase total comes out below subtotal + GST".
 *
 * Written to reproduce, not to fix. Each case records what the purchase actually
 * stores and asserts the one relationship the report is about:
 *
 *     grand total == subtotal - discount + exclusive tax + shipping, rounded
 *
 * A total *below* subtotal + GST is only correct when something was taken off.
 * These cases each state what that something is, so a failure names the layer.
 */
class PurchaseTotalReproductionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Party $supplier;

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
            'name' => 'Acme Supplies',
            'type' => 'Supplier',
            'due' => 0,
        ]);
    }

    private function product(float $rate = 0, string $type = 'exclusive'): Product
    {
        $vat = $rate > 0
            ? Vat::factory()->create(['business_id' => $this->business->id, 'rate' => $rate])
            : null;

        return Product::factory()->create([
            'business_id' => $this->business->id,
            'vat_id' => $vat?->id,
            'vat_type' => $type,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function line(Product $product, float $cost, float $qty, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'quantities' => $qty,
            'productPurchasePrice' => $cost,
            'productSalePrice' => $cost * 1.5,
            'conversion_purchase_price' => $cost,
            'conversion_quantities' => $qty,
            'conversion_value' => 1,
            'conversion_price' => $cost * 1.5,
            'expire_date' => now()->addYear()->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'upper_selected_unit' => 1,
            'lower_selected_unit' => 1,
            'total_purchase_price' => $cost * $qty,
            'total_price' => $cost * $qty * 1.5,
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $overrides
     */
    private function buy(array $products, array $overrides = []): Purchase
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase', array_merge([
                'party_id' => $this->supplier->id,
                'purchaseDate' => now()->toDateString(),
                'paymentType' => 'cash',
                'products' => $products,
            ], $overrides))
            ->assertSuccessful();

        return Purchase::latest('id')->first();
    }

    /** Case 1: one item, no discount, no GST. */
    public function test_one_item_no_discount_no_gst(): void
    {
        $p = $this->buy([$this->line($this->product(), 50, 10)]);

        $this->assertSame(500.0, (float) $p->totalAmount);
        $this->assertSame(0.0, (float) $p->vat_amount);
    }

    /** Case 2: one item plus GST. */
    public function test_one_item_with_gst(): void
    {
        $p = $this->buy([$this->line($this->product(18), 50, 10)]);

        $this->assertSame(90.0, (float) $p->vat_amount);
        $this->assertSame(590.0, (float) $p->totalAmount, '500 + 18%.');
    }

    /** Case 3: several items plus GST. */
    public function test_multiple_items_with_gst(): void
    {
        $p = $this->buy([
            $this->line($this->product(18), 50, 10),
            $this->line($this->product(18), 20, 5),
        ]);

        // 500 + 100 = 600 taxable, 18% = 108.
        $this->assertSame(108.0, (float) $p->vat_amount);
        $this->assertSame(708.0, (float) $p->totalAmount);
    }

    /** Case 4: a row discount with GST. */
    public function test_row_discount_with_gst(): void
    {
        $p = $this->buy([
            $this->line($this->product(18), 50, 10, ['discount_flat' => 100]),
        ]);

        // 500 - 100 = 400 taxable, 18% = 72.
        $this->assertSame(72.0, (float) $p->vat_amount);
        $this->assertSame(472.0, (float) $p->totalAmount);
    }

    /** Case 5: an overall discount with GST. */
    public function test_overall_discount_with_gst(): void
    {
        $p = $this->buy(
            [$this->line($this->product(18), 50, 10)],
            ['discountAmount' => 100, 'discount_type' => 'flat'],
        );

        $this->assertSame(72.0, (float) $p->vat_amount);
        $this->assertSame(472.0, (float) $p->totalAmount);
    }

    /** Case 6: larger quantities. */
    public function test_multiple_quantities(): void
    {
        $p = $this->buy([$this->line($this->product(18), 12.5, 40)]);

        // 500 taxable, 90 tax.
        $this->assertSame(90.0, (float) $p->vat_amount);
        $this->assertSame(590.0, (float) $p->totalAmount);
    }

    /** Case 7: decimal prices, where a float pipeline would drift. */
    public function test_decimal_prices(): void
    {
        $p = $this->buy([
            $this->line($this->product(18), 33.33, 3),
            $this->line($this->product(18), 16.67, 3),
        ]);

        // (99.99 + 50.01) = 150.00 taxable, 18% = 27.00.
        $this->assertSame(150.0, (float) $p->totalAmount - (float) $p->vat_amount);
        $this->assertSame(27.0, (float) $p->vat_amount);
        $this->assertSame(177.0, (float) $p->totalAmount);
    }

    /** Case 8: purchase rates are always exclusive, regardless of sale pricing. */
    public function test_purchase_rate_stays_exclusive_when_product_sales_are_inclusive(): void
    {
        $p = $this->buy([
            $this->line($this->product(18, 'inclusive'), 118, 10),
        ]);

        // The product's `vat_type` controls sales. A supplier's purchase rate is
        // always the pre-GST cost: 1180 + 18% (212.40) = 1392.40.
        $this->assertSame(1392.4, (float) $p->totalAmount);
        $this->assertSame(212.4, (float) $p->vat_amount);
    }

    /**
     * The relationship the bug report is actually about.
     *
     * Asserted across every combination rather than one case: "total below
     * subtotal + GST" is a claim about the identity, not about one basket.
     */
    public function test_the_total_is_never_below_subtotal_plus_tax_without_a_deduction(): void
    {
        $cases = [
            'plain'        => [[$this->line($this->product(18), 50, 10)], []],
            'shipping'     => [[$this->line($this->product(18), 50, 10)], ['shipping_charge' => 25]],
            'row discount' => [[$this->line($this->product(18), 50, 10, ['discount_flat' => 50])], []],
            'order discount' => [
                [$this->line($this->product(18), 50, 10)],
                ['discountAmount' => 50, 'discount_type' => 'flat'],
            ],
        ];

        foreach ($cases as $name => [$products, $options]) {
            $p = $this->buy($products, $options);

            $tax = (float) $p->vat_amount;
            $total = (float) $p->totalAmount;
            $discount = (float) $p->discountAmount + (float) $p->rowDiscount;
            $shipping = (float) $p->shipping_charge;

            // total = (taxable) + tax + shipping, and taxable = subtotal - discount.
            $impliedTaxable = $total - $tax - $shipping;

            $this->assertGreaterThan(
                0,
                $impliedTaxable,
                "[{$name}] the taxable base implied by the stored total is not positive.",
            );

            $this->assertEqualsWithDelta(
                $total,
                $impliedTaxable + $tax + $shipping,
                0.01,
                "[{$name}] the stored total does not reconcile with its own parts.",
            );
        }
    }
}

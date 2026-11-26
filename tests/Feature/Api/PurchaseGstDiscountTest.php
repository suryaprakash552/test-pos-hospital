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
 * GST against discounts on the purchase side.
 *
 * The purchase calculator carried the same fault as the sale one: an order-level
 * discount was subtracted after tax had already been summed per line, so a row
 * discount reduced GST and an identical order discount did not. The persisted
 * `vat_amount` and `totalAmount` therefore depended on which box the figure was
 * typed into rather than on what was actually bought.
 */
class PurchaseGstDiscountTest extends TestCase
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

    private function product(float $rate = 0): Product
    {
        $vat = $rate > 0
            ? Vat::factory()->create(['business_id' => $this->business->id, 'rate' => $rate])
            : null;

        return Product::factory()->create([
            'business_id' => $this->business->id,
            'vat_id' => $vat?->id,
            'vat_type' => 'exclusive',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function line(Product $product, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $product->id,
            'quantities' => 10,
            'productPurchasePrice' => 50,
            'productSalePrice' => 80,
            'conversion_purchase_price' => 50,
            'conversion_quantities' => 10,
            'conversion_value' => 1,
            'conversion_price' => 80,
            'expire_date' => now()->addYear()->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'upper_selected_unit' => 1,
            'lower_selected_unit' => 1,
            'total_purchase_price' => 500,
            'total_price' => 800,
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $overrides
     */
    private function purchase(array $products, array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/purchase', array_merge([
            'party_id' => $this->supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => $products,
        ], $overrides));
    }

    public function test_gst_is_charged_on_the_undiscounted_line_when_there_is_no_discount(): void
    {
        $this->purchase([$this->line($this->product(18))])->assertSuccessful();

        $purchase = Purchase::first();
        $this->assertSame(90.0, (float) $purchase->vat_amount, '18% of 500.');
        $this->assertSame(590.0, (float) $purchase->totalAmount);
    }

    public function test_an_order_discount_reduces_the_taxable_base(): void
    {
        $this->purchase(
            [$this->line($this->product(18))],
            ['discountAmount' => 100, 'discount_type' => 'flat'],
        )->assertSuccessful();

        $purchase = Purchase::first();

        // 500 - 100 = 400 taxable, 18% = 72.
        $this->assertSame(72.0, (float) $purchase->vat_amount);
        $this->assertSame(472.0, (float) $purchase->totalAmount);
    }

    public function test_a_row_discount_and_an_order_discount_bill_identically(): void
    {
        $this->purchase([$this->line($this->product(18), ['discount_flat' => 100])])
            ->assertSuccessful();
        $viaRow = Purchase::latest('id')->first();

        $this->purchase(
            [$this->line($this->product(18))],
            ['discountAmount' => 100, 'discount_type' => 'flat'],
        )->assertSuccessful();
        $viaOrder = Purchase::latest('id')->first();

        $this->assertSame(
            (float) $viaRow->vat_amount,
            (float) $viaOrder->vat_amount,
            'Where the discount was entered must not change the GST.',
        );
        $this->assertSame((float) $viaRow->totalAmount, (float) $viaOrder->totalAmount);
    }

    public function test_the_discount_is_apportioned_across_mixed_rates(): void
    {
        $this->purchase(
            [
                $this->line($this->product(18)),
                $this->line($this->product(0)),
            ],
            ['discountAmount' => 500, 'discount_type' => 'flat'],
        )->assertSuccessful();

        $purchase = Purchase::first();

        // 500 off a 1000 subtotal: 250 to each line. Only one line has a rate,
        // so GST is 18% of (500 - 250) = 45. A discount cannot be steered onto
        // the taxed line to shrink what is due.
        $this->assertSame(45.0, (float) $purchase->vat_amount);
        $this->assertSame(545.0, (float) $purchase->totalAmount);
    }

    public function test_a_percentage_order_discount_reduces_the_taxable_base(): void
    {
        $this->purchase(
            [$this->line($this->product(18))],
            ['discount_percent' => 20, 'discount_type' => 'percent'],
        )->assertSuccessful();

        $purchase = Purchase::first();

        // 20% of 500 is 100 off, leaving 400 taxable.
        $this->assertSame(72.0, (float) $purchase->vat_amount);
        $this->assertSame(472.0, (float) $purchase->totalAmount);
    }
}

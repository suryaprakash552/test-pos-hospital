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
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end proof that the checkout endpoint decides the money, not the client.
 *
 * The unit tests pin the arithmetic; these pin that the arithmetic is what actually
 * reaches the database when a real request comes in claiming otherwise.
 */
class AuthoritativeCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
    }

    /**
     * A product with a batch, stocked and purchasable.
     */
    private function sellableStock(float $price = 100, float $quantity = 10, float $rate = 0): Stock
    {
        $vat = $rate > 0
            ? Vat::factory()->create(['business_id' => $this->business->id, 'rate' => $rate])
            : null;

        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'productSalePrice' => $price,
            'vat_id' => $vat?->id,
            'vat_type' => 'exclusive',
        ]);

        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productSalePrice' => $price,
            'productPurchasePrice' => 60,
            'productStock' => $quantity,
            'conversionValue' => 1,
        ]);

        // The FIFO consumption walks purchase_details, so a batch needs a purchase
        // behind it or nothing is available to sell.
        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 60 * $quantity,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => $quantity,
            'conversionQuantity' => $quantity,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => $price,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        return $stock;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, mixed>  $overrides
     */
    private function checkout(array $products, array $overrides = [], array $headers = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales', array_merge([
            'products' => json_encode($products),
            'saleDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'totalAmount' => 0,
        ], $overrides), $headers);
    }

    public function test_a_manipulated_line_price_does_not_reach_the_sale(): void
    {
        $stock = $this->sellableStock(price: 100);

        $response = $this->checkout([
            [
                'stock_id' => $stock->id,
                'quantities' => 2,
                // The client says these are a rupee each.
                'price' => 1,
                'conversion_price' => 1,
                'lossProfit' => 0,
            ],
        ], ['paidAmount' => 200, 'totalAmount' => 2]);

        $response->assertStatus(200);

        $sale = Sale::latest('id')->first();
        $this->assertSame(200.0, (float) $sale->totalAmount);

        $detail = SaleDetails::where('sale_id', $sale->id)->first();
        $this->assertSame(100.0, (float) $detail->price);
    }

    public function test_a_manipulated_total_is_replaced_by_the_calculated_one(): void
    {
        $stock = $this->sellableStock(price: 250);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 4, 'price' => 250, 'lossProfit' => 0],
        ], ['totalAmount' => 1, 'paidAmount' => 1000])->assertStatus(200);

        $this->assertSame(1000.0, (float) Sale::latest('id')->first()->totalAmount);
    }

    public function test_a_manipulated_tax_amount_is_recalculated(): void
    {
        $stock = $this->sellableStock(price: 100, rate: 18);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], [
            'vat_amount' => 9999,
            'totalAmount' => 118,
            'paidAmount' => 118,
        ])->assertStatus(200);

        $sale = Sale::latest('id')->first();
        $this->assertSame(18.0, (float) $sale->vat_amount);
        $this->assertSame(118.0, (float) $sale->totalAmount);
    }

    public function test_an_excessive_discount_is_rejected(): void
    {
        $stock = $this->sellableStock(price: 100);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['discountAmount' => 1_000_000, 'paidAmount' => 0])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'discount_exceeds_subtotal');

        $this->assertSame(0, Sale::count());
    }

    public function test_another_tenants_stock_cannot_be_sold(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreignProduct = Product::factory()->create(['business_id' => $otherBusiness->id]);
        $foreignStock = Stock::factory()->create([
            'business_id' => $otherBusiness->id,
            'product_id' => $foreignProduct->id,
            'productStock' => 100,
        ]);

        $this->checkout([
            ['stock_id' => $foreignStock->id, 'quantities' => 1, 'price' => 10, 'lossProfit' => 0],
        ], ['paidAmount' => 10])->assertStatus(422);

        $this->assertSame(0, Sale::count());
    }

    public function test_another_tenants_customer_cannot_be_billed(): void
    {
        $stock = $this->sellableStock(price: 100);

        $otherBusiness = Business::factory()->create();
        $foreignCustomer = Customer::factory()->create(['business_id' => $otherBusiness->id]);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['customer_id' => $foreignCustomer->id, 'paidAmount' => 100])
            ->assertStatus(422);

        $this->assertSame(0, Sale::count());
    }

    /** @return array<string, array{mixed, int}> */
    public static function provideRejectedQuantities(): array
    {
        return [
            'zero' => [0, 422],
            'negative' => [-5, 422],
        ];
    }

    /** @dataProvider provideRejectedQuantities */
    public function test_invalid_quantities_are_rejected(mixed $quantity, int $status): void
    {
        $stock = $this->sellableStock(price: 100);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => $quantity, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 0])->assertStatus($status);

        $this->assertSame(0, Sale::count());
    }

    public function test_a_quantity_beyond_stock_is_rejected(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 3);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 50, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 5000])->assertStatus(406);

        $this->assertSame(0, Sale::count());
    }

    public function test_due_is_calculated_server_side_not_accepted(): void
    {
        $stock = $this->sellableStock(price: 100);
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'due' => 0,
        ]);

        // Pays 40 of a 100 bill but claims nothing is owed.
        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], [
            'customer_id' => $customer->id,
            'paidAmount' => 40,
            'dueAmount' => 0,
            'totalAmount' => 100,
        ])->assertStatus(200);

        $sale = Sale::latest('id')->first();
        $this->assertSame(60.0, (float) $sale->dueAmount);
        $this->assertSame(40.0, (float) $sale->paidAmount);

        // And the customer carries that due.
        $this->assertSame(60.0, (float) $customer->fresh()->due);
    }

    public function test_change_is_calculated_not_accepted(): void
    {
        $stock = $this->sellableStock(price: 100);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 500, 'change_amount' => 0])->assertStatus(200);

        $sale = Sale::latest('id')->first();
        $this->assertSame(400.0, (float) $sale->change_amount);
        $this->assertSame(100.0, (float) $sale->paidAmount);
        $this->assertSame(0.0, (float) $sale->dueAmount);
    }

    public function test_stock_is_deducted_exactly_once(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 10);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 3, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 300])->assertStatus(200);

        $this->assertSame(7.0, (float) $stock->fresh()->productStock);
    }

    public function test_a_retried_checkout_creates_one_sale(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 10);

        $products = [
            ['stock_id' => $stock->id, 'quantities' => 2, 'price' => 100, 'lossProfit' => 0],
        ];

        $headers = ['Idempotency-Key' => 'till-1-checkout-abc123'];

        $first = $this->checkout($products, ['paidAmount' => 200], $headers);
        $first->assertStatus(200);

        // The till never saw the response and sends the identical request again.
        $second = $this->checkout($products, ['paidAmount' => 200], $headers);
        $second->assertStatus(200);

        $this->assertSame(1, Sale::count(), 'The retry created a second sale.');

        // And the stock moved only once.
        $this->assertSame(8.0, (float) $stock->fresh()->productStock);
    }

    public function test_a_reused_key_with_a_different_cart_is_refused(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 10);
        $headers = ['Idempotency-Key' => 'till-1-checkout-xyz'];

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 100], $headers)->assertStatus(200);

        // Same key, larger cart: a client bug, not a retry.
        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 5, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 500], $headers)
            ->assertStatus(422)
            ->assertJsonPath('reason', 'idempotency_key_reused');

        $this->assertSame(1, Sale::count());
    }

    public function test_a_failed_checkout_does_not_burn_the_idempotency_key(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 2);
        $headers = ['Idempotency-Key' => 'till-1-retry-after-fix'];

        // Fails: more than the batch holds.
        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 99, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 9900], $headers)->assertStatus(406);

        // Corrected and retried with the same key - must be allowed through.
        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 100], $headers)->assertStatus(200);

        $this->assertSame(1, Sale::count());
    }

    public function test_the_sale_total_matches_the_sum_of_its_lines(): void
    {
        $first = $this->sellableStock(price: 33.33, quantity: 10);
        $second = $this->sellableStock(price: 66.67, quantity: 10);

        $this->checkout([
            ['stock_id' => $first->id, 'quantities' => 3, 'price' => 33.33, 'lossProfit' => 0],
            ['stock_id' => $second->id, 'quantities' => 3, 'price' => 66.67, 'lossProfit' => 0],
        ], ['paidAmount' => 300])->assertStatus(200);

        $sale = Sale::latest('id')->first();

        $lineSum = DB::table('sale_details')
            ->where('sale_id', $sale->id)
            ->selectRaw('sum(price * quantities) as total')
            ->value('total');

        $this->assertSame(300.0, round((float) $lineSum, 2));
        $this->assertSame(300.0, (float) $sale->totalAmount);
    }
    // ── The row discount the operator typed ─────────────────────────────────

    /**
     * A row discount must survive the request.
     *
     * The controller prices from `validated('products_payload')`, and `validated()`
     * returns only the keys that have rules. The line discount fields had none,
     * so they were stripped between the request and the calculator — which then
     * fell back to the discount configured on the *product*.
     *
     * Reported from the counter exactly as it behaves: a bill showing ₹4 off on
     * the billing screen was saved with ₹448 off, with GST recomputed on that
     * wrong base, so the invoice's discount, tax and total were all wrong while
     * the bill amount was right.
     */
    public function test_a_row_discount_is_not_replaced_by_the_products_own(): void
    {
        $stock = $this->sellableStock(price: 6, quantity: 300);

        // The product carries a standing 30% discount, which is what used to be
        // applied instead of what was typed.
        $stock->product->forceFill(['product_percent_discount' => 30])->save();

        $this->checkout([
            [
                'stock_id' => $stock->id,
                'quantities' => 249,
                'price' => 6,
                'conversion_price' => 6,
                'lossProfit' => 0,
                // What the operator actually entered: ₹4 off the row.
                'discount_flat' => 4,
                'discount_percent' => 0,
                'discount_amount' => 4,
            ],
        ], ['paidAmount' => 1490])->assertStatus(200);

        $sale = Sale::latest('id')->first();

        // 249 x 6 = 1494, less the ₹4 typed — not less the ₹448.20 the product
        // would have imposed.
        $this->assertEqualsWithDelta(4.0, (float) $sale->rowDiscount, 0.01);
        $this->assertEqualsWithDelta(1490.0, (float) $sale->totalAmount, 0.01);
    }

    /**
     * The preview and the save must agree, because the shop approves the preview.
     *
     * They read the same lines through different doors — `calculate()` from the
     * raw input, `store()` from the validated payload — and that is precisely
     * how they came to disagree.
     */
    public function test_the_previewed_total_is_the_total_that_gets_saved(): void
    {
        $stock = $this->sellableStock(price: 6, quantity: 300);
        $stock->product->forceFill(['product_percent_discount' => 30])->save();

        $line = [
            'stock_id' => $stock->id,
            'quantities' => 249,
            'price' => 6,
            'conversion_price' => 6,
            'lossProfit' => 0,
            'discount_flat' => 4,
            'discount_amount' => 4,
        ];

        $preview = $this->actingAs($this->user)->postJson('/api/v1/sales/calculate', [
            'products_payload' => [$line],
        ])->assertStatus(200);

        $previewed = (float) $preview->json('data.grand_total');

        $this->checkout([$line], ['paidAmount' => $previewed])->assertStatus(200);

        $this->assertEqualsWithDelta(
            $previewed,
            (float) Sale::latest('id')->first()->totalAmount,
            0.01,
            'The saved bill must be the bill the shop approved.',
        );
    }

    /**
     * Who the medicine on a bill was for is a fact about the *sale*.
     *
     * The till asks for a relationship on every sale and the app has always
     * sent one, but `sales` had no column for it, so it was dropped and the
     * invoice fell back to the customer's own standing relationship. A customer
     * buying for their wife got a bill that read "self".
     */
    public function test_a_sale_records_who_it_was_for(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 10);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 100, 'relationship' => 'wife'])->assertStatus(200);

        $this->assertSame('wife', Sale::latest('id')->first()->relationship);
    }

    /** The same customer can buy for different people on different days. */
    public function test_two_sales_to_one_customer_can_record_different_people(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 10);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 100, 'relationship' => 'self'])->assertStatus(200);

        $first = Sale::latest('id')->first()->relationship;

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 100, 'relationship' => 'father'])->assertStatus(200);

        $this->assertSame('self', $first);
        $this->assertSame('father', Sale::latest('id')->first()->relationship);
    }

    /**
     * A sale records the rate it was taxed at, so GST can be filed rate by rate.
     *
     * `sales.vat_percent` is what the GST report groups and filters on, and
     * nothing ever wrote it — every sale stored 0. The report bucketed a whole
     * period under "0% GST" while still showing the tax collected, and choosing
     * 5% or 18% in the rate filter matched no rows at all.
     */
    public function test_a_sale_records_the_rate_it_was_taxed_at(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 10, rate: 18);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 118])->assertStatus(200);

        $this->assertEqualsWithDelta(
            18.0,
            (float) Sale::latest('id')->first()->vat_percent,
            0.01,
        );
    }

    /** An untaxed sale files under nothing rather than a made-up rate. */
    public function test_an_untaxed_sale_records_no_rate(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 10);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 1, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 100])->assertStatus(200);

        $this->assertEqualsWithDelta(
            0.0,
            (float) Sale::latest('id')->first()->vat_percent,
            0.01,
        );
    }

    /**
     * With nothing typed on the row, nothing comes off.
     *
     * The product's own discount is the ceiling the till enforces while the
     * operator types, not a standing offer. Applying it here took ₹165 off a
     * bill the counter had rung up at full price, on a billing screen that
     * still read "no overall discount applied".
     */
    public function test_the_products_own_discount_is_never_applied_on_its_own(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 20);
        $stock->product->forceFill([
            'product_percent_discount' => 10,
            'product_flat_discount' => 5,
        ])->save();

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 2, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 200])->assertStatus(200);

        $sale = Sale::latest('id')->first();

        $this->assertEqualsWithDelta(0.0, (float) $sale->rowDiscount, 0.01);
        $this->assertEqualsWithDelta(200.0, (float) $sale->totalAmount, 0.01);
    }
    // ── The product's own movement history ──────────────────────────────────

    /**
     * A sale has to appear in the product's inventory history.
     *
     * Reported as "in inventory, clicking product details history shows only
     * purchase history and no sales". The recorder stamps `product_id` from the
     * batch, so this pins that a real checkout actually lands there.
     */
    public function test_a_sale_appears_in_the_products_inventory_history(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 20);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 3, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 300])->assertStatus(200);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/products/{$stock->product_id}/inventory-history")
            ->assertOk();

        $events = collect($response->json('data'))->pluck('event_type');

        $this->assertTrue(
            $events->contains('sale_out'),
            'the history showed '.$events->implode(', ').' and no sale',
        );
    }

    /** And the movement is signed the way stock actually moved. */
    public function test_the_sale_movement_is_negative(): void
    {
        $stock = $this->sellableStock(price: 100, quantity: 20);

        $this->checkout([
            ['stock_id' => $stock->id, 'quantities' => 3, 'price' => 100, 'lossProfit' => 0],
        ], ['paidAmount' => 300])->assertStatus(200);

        $sale = collect(
            $this->actingAs($this->user)
                ->getJson("/api/v1/products/{$stock->product_id}/inventory-history")
                ->json('data')
        )->firstWhere('event_type', 'sale_out');

        $this->assertNotNull($sale);
        $this->assertLessThan(0, (float) $sale['quantity_delta']);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\InventoryChangeLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Creating a sale — the happy path.
 *
 * Every other test that posts to this endpoint asserts a validation failure, so
 * nothing exercised the controller body. That gap hid a defect that made the
 * endpoint impossible to complete at all: the availability check referenced
 * `product_id` without naming a table, and because that column exists on both
 * `purchase_details` and `stocks`, MySQL rejected the query as ambiguous. Every
 * sale returned 500.
 *
 * A till that cannot record a sale is the whole product failing, so this covers the
 * path end to end: the sale is written, the batch is drawn down, and the stock
 * figure moves by the amount sold.
 */
class SaleCreationTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;
    protected Product $product;
    protected Stock $stock;
    protected Customer $customer;
    protected int $purchaseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();

        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->product = Product::create([
            'productName'      => 'IV Set',
            'productCode'      => 'IV-1',
            'business_id'      => $this->business->id,
            'category_id'      => $category->id,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        $this->stock = Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $this->product->id,
            'batch_no'         => 'B1',
            'productStock'     => 50,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        // Lots hang off a purchase, so there has to be one for them to belong to.
        $this->purchaseId = DB::table('purchases')->insertGetId([
            'business_id'      => $this->business->id,
            'invoiceNumber'    => 'P-1',
            'purchaseDate'     => '2026-07-01',
            'discountAmount'   => 0,
            'shipping_charge'  => 0,
            'discount_percent' => 0,
            'discount_type'    => 'fixed',
            'rowDiscount'      => 0,
            'dueAmount'        => 0,
            'paidAmount'       => 0,
            'cash_amount'      => 0,
            'upi_amount'       => 0,
            'card_amount'      => 0,
            'change_amount'    => 0,
            'totalAmount'      => 0,
            'isPaid'           => 1,
            'vat_percent'      => 0,
            'vat_amount'       => 0,
        ]);

        // The lot the sale draws from. `conversionQuantity` is what remains of it,
        // and the availability check sums exactly this.
        $this->lot($this->stock->id, 50);

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Walk-in',
            'phone'       => '9999900000',
            'relationship' => 'self',
        ]);
    }

    /**
     * A purchase lot behind a batch.
     *
     * `conversionQuantity` is what remains of it, and the availability check sums
     * exactly this column. The table carries no business_id — the business is
     * reached through `stocks`, which is why that join exists at all.
     */
    private function lot(int $stockId, float $remaining): void
    {
        DB::table('purchase_details')->insert([
            'purchase_id'             => $this->purchaseId,
            'product_id'              => $this->product->id,
            'stock_id'                => $stockId,
            'quantities'              => $remaining,
            'conversionQuantity'      => $remaining,
            'productDealerPrice'      => 0,
            'productPurchasePrice'    => 0,
            'conversionPurchasePrice' => 0,
            'productSalePrice'        => 100,
            'productWholeSalePrice'   => 0,
            'purchaseUpperLimit'      => '',
            'purchaseLowerLimit'      => '',
            'totalPrice'              => 0,
            'totalPurchasePrice'      => 0,
        ]);
    }

    private function sell(array $lines, array $over = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales', array_merge([
            'saleDate'    => '2026-08-01',
            'customer_id' => $this->customer->id,
            'totalAmount' => 300,
            'paidAmount'  => 300,
            'products'    => json_encode($lines),
        ], $over));
    }

    private function line(array $over = []): array
    {
        return array_merge([
            'stock_id'   => $this->stock->id,
            'quantities' => 3,
            'price'      => 100,
            'lossProfit' => 10,
        ], $over);
    }

    /** The regression: this returned 500 for every sale. */
    public function test_a_sale_can_be_created(): void
    {
        $this->sell([$this->line()])->assertStatus(200);

        $this->assertDatabaseHas('sales', [
            'business_id' => $this->business->id,
            'totalAmount' => 300,
        ]);
    }

    public function test_the_sold_quantity_is_recorded_against_the_product(): void
    {
        $this->sell([$this->line()])->assertStatus(200);

        $this->assertDatabaseHas('sale_details', [
            'product_id' => $this->product->id,
            'quantities' => 3,
        ]);
    }

    public function test_sales_reports_use_the_relationship_saved_on_the_sale(): void
    {
        $this->sell([$this->line()], ['relationship' => 'wife'])->assertStatus(200);

        $this->assertDatabaseHas('sales', [
            'customer_id' => $this->customer->id,
            'relationship' => 'wife',
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales?relationship=wife')
            ->assertSuccessful()
            ->assertJsonPath('data.data.0.relationship', 'wife');

        // The customer profile remains self. The product history must still
        // tell the pharmacist who this particular medicine was sold for.
        $this->actingAs($this->user)
            ->getJson('/api/v1/product-report?product_id='.$this->product->id)
            ->assertSuccessful()
            ->assertJsonPath('data.0.relationship', 'wife');
    }

    /** What was sold has to leave the batch, or the next sale oversells it. */
    public function test_the_batch_is_drawn_down_by_what_was_sold(): void
    {
        $this->sell([$this->line()])->assertStatus(200);

        $this->assertEquals(
            47,
            DB::table('purchase_details')
                ->where('stock_id', $this->stock->id)
                ->sum('conversionQuantity'),
        );

        $this->assertDatabaseHas('inventory_change_logs', [
            'event_type' => 'sale_out',
            'stock_id' => $this->stock->id,
            'product_id' => $this->product->id,
            'quantity_delta' => -3,
        ]);
    }

    public function test_the_stock_figure_moves_with_the_sale(): void
    {
        $this->sell([$this->line()])->assertStatus(200);

        $this->assertEquals(47, $this->stock->fresh()->productStock);
    }

    /**
     * Refused, not recorded: overselling would leave the batch negative and the
     * shelf disagreeing with the system.
     */
    public function test_selling_more_than_the_batch_holds_is_refused(): void
    {
        $this->sell([$this->line(['quantities' => 999])])->assertStatus(406);

        $this->assertDatabaseCount('sales', 0);
    }

    /**
     * The refusal names the item and says how much there actually is.
     *
     * The name is read from the server's own record rather than the client's
     * payload: taking it from the payload alone left it blank whenever the client
     * did not send one, so on a multi-line sale the cashier could not tell which
     * item was short.
     */
    public function test_the_refusal_names_the_product_and_what_is_available(): void
    {
        $this->sell([$this->line(['quantities' => 999])])
            ->assertStatus(406)
            ->assertJsonFragment([
                'message' => 'Stock not available for product : IV Set. Available stock is : 50.0000',
            ]);
    }

    public function test_a_sale_across_two_lines_draws_down_both(): void
    {
        $second = Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $this->product->id,
            'batch_no'         => 'B2',
            'productStock'     => 20,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        $this->lot($second->id, 20);

        $this->sell([
            $this->line(['quantities' => 2]),
            $this->line(['stock_id' => $second->id, 'quantities' => 5]),
        ])->assertStatus(200);

        $this->assertEquals(48, $this->stock->fresh()->productStock);
        $this->assertEquals(15, $second->fresh()->productStock);
    }

    /** Nothing is written when any line fails, so a sale is never half-recorded. */
    public function test_a_failing_line_rolls_the_whole_sale_back(): void
    {
        // Distinct batches: two lines against one batch are rejected earlier, by
        // the `distinct` rule, which would not exercise the rollback.
        $second = Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $this->product->id,
            'batch_no'         => 'B2',
            'productStock'     => 5,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        $this->lot($second->id, 5);

        $this->sell([
            $this->line(['quantities' => 2]),
            $this->line(['stock_id' => $second->id, 'quantities' => 999]),
        ])->assertStatus(406);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_details', 0);
        $this->assertEquals(50, $this->stock->fresh()->productStock);
    }

    // ── Which batch the stock is actually in ─────────────────────────────────

    /**
     * A product with stock in one batch and none in another.
     *
     * The till sells from a *batch*, not from a product, so selling out of the
     * empty one has to be refused however much the product holds elsewhere. What
     * matters is that the refusal says something the cashier can act on: the
     * product list shows a product-level figure, so "there is stock" and "this
     * sale is refused" are both true at once and the message has to reconcile them.
     */
    public function test_selling_from_an_empty_batch_is_refused(): void
    {
        $empty = Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $this->product->id,
            'batch_no'         => 'EMPTY',
            'productStock'     => 0,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        $this->lot($empty->id, 0);

        $response = $this->sell([
            $this->line(['stock_id' => $empty->id, 'quantities' => 1]),
        ]);

        $this->assertContains($response->status(), [400, 406]);
        $this->assertDatabaseCount('sales', 0);

        // The message must name the product and the batch's own figure. Quoting a
        // product-wide total here is what makes a refusal look like a bug: it says
        // there are 50 in stock while refusing to sell one.
        $message = $response->json('message');

        $this->assertStringContainsString(
            'IV Set',
            $message,
            'the cashier has to know which item was refused',
        );
        $this->assertStringNotContainsString(
            '50',
            $message,
            'quoting the product-wide total while refusing the batch reads as a fault',
        );
    }

    /** The batch that does hold stock still sells, unaffected. */
    public function test_the_stocked_batch_still_sells(): void
    {
        Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $this->product->id,
            'batch_no'         => 'EMPTY',
            'productStock'     => 0,
            'productSalePrice' => 100,
            'conversionValue'  => 1,
        ]);

        $this->sell([$this->line(['quantities' => 5])])->assertStatus(200);
    }
}

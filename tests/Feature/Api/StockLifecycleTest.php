<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Stock through a whole cycle: bought, sold, and what remains.
 *
 * Each half is covered on its own elsewhere. This walks the sequence a shop
 * actually performs, through the real endpoints, because the reported faults were
 * all of the form "I bought three and it says two" — a discrepancy that only
 * appears once the two halves are put together and the arithmetic is compared
 * against what a person counted on a shelf.
 */
class StockLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Business $business;
    private Party $party;
    private Customer $customer;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'owner',
        ]);
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->party = Party::create([
            'name'        => 'Supplier',
            'business_id' => $this->business->id,
            'type'        => 'Supplier',
            'phone'       => '9000000888',
        ]);
        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'name'        => 'Walk-in',
            'phone'       => '9000000999',
        ]);

        $this->actingAs($this->user);
    }

    private function product(string $code): Product
    {
        return Product::create([
            'productName'  => 'Item ' . $code,
            'business_id'  => $this->business->id,
            'category_id'  => $this->category->id,
            'productCode'  => $code,
            'productStock' => 0,
        ]);
    }

    /** Buys [strips] of [product], each holding [conversion] sellable units. */
    private function buy(Product $product, int $strips, int $conversion = 1, string $batch = 'B-1'): void
    {
        $this->postJson('/api/v1/purchase', [
            'party_id'    => $this->party->id,
            'paidAmount'  => 0,
            'dueAmount'   => 0,
            'totalAmount' => 100,
            'paymentType' => 'Cash',
            'products'    => [[
                'product_id'                => $product->id,
                'batch_no'                  => $batch,
                'quantities'                => $strips,
                'conversion_quantities'     => $strips * $conversion,
                'conversion_value'          => $conversion,
                'productSalePrice'          => 30,
                'productPurchasePrice'      => 20,
                'conversion_purchase_price' => 20,
                'conversion_price'          => 30,
                'total_purchase_price'      => 60,
                'total_price'               => 90,
                'expire_date'               => '2030-01-01',
                'mfg_date'                  => '2026-01-01',
                'upper_selected_unit'       => 1,
                'lower_selected_unit'       => 1,
            ]],
        ])->assertOk();
    }

    private function stockIdFor(Product $product, string $batch = 'B-1'): int
    {
        return (int) DB::table('stocks')
            ->where('product_id', $product->id)
            ->where('batch_no', $batch)
            ->value('id');
    }

    /** Sells [quantity] sellable units from the batch. */
    private function sell(Product $product, float $quantity, string $batch = 'B-1')
    {
        return $this->postJson('/api/v1/sales', [
            'customer_id'  => $this->customer->id,
            'saleDate'     => now()->toDateString(),
            'totalAmount'  => 30 * $quantity,
            'paidAmount'   => 30 * $quantity,
            'dueAmount'    => 0,
            'paymentType'  => 'cash',
            // The endpoint takes its line items as a JSON string.
            'products'     => json_encode([[
                'product_id' => $product->id,
                'stock_id'   => $this->stockIdFor($product, $batch),
                'quantities' => $quantity,
                'price'      => 30,
                'lossProfit' => 10,
                'productPurchasePrice' => 20,
            ]]),
        ]);
    }

    /** What the till would still let the shop sell. */
    private function remaining(Product $product): float
    {
        return (float) DB::table('purchase_details')
            ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
            ->where('stocks.product_id', $product->id)
            ->sum('purchase_details.conversionQuantity');
    }

    /** The reported case, end to end: buy three, sell one, two remain. */
    public function test_buying_three_and_selling_one_leaves_two(): void
    {
        $product = $this->product('CYCLE');

        $this->buy($product, 3);
        $this->assertSame(3.0, $this->remaining($product), 'three bought');

        $this->sell($product, 1)->assertOk();

        $this->assertSame(2.0, $this->remaining($product), 'one sold, two left');
    }

    /** Selling everything leaves nothing, and does not go negative. */
    public function test_selling_the_whole_batch_leaves_zero(): void
    {
        $product = $this->product('ALL');

        $this->buy($product, 3);
        $this->sell($product, 3)->assertOk();

        $this->assertSame(0.0, $this->remaining($product));
    }

    /** One more than the shop holds is refused, and nothing is drawn down. */
    public function test_selling_more_than_is_held_is_refused(): void
    {
        $product = $this->product('OVER');

        $this->buy($product, 3);

        $response = $this->sell($product, 4);

        $this->assertContains($response->status(), [400, 406]);
        $this->assertSame(
            3.0,
            $this->remaining($product),
            'a refused sale must not consume stock',
        );
    }

    /**
     * With a conversion, a sale is counted in the unit it draws down.
     *
     * Three strips of ten is thirty tablets; selling two tablets leaves
     * twenty-eight, not one strip short.
     */
    public function test_a_sale_draws_down_in_the_selling_unit(): void
    {
        $product = $this->product('STRIP');

        $this->buy($product, 3, conversion: 10);
        $this->assertSame(30.0, $this->remaining($product));

        $this->sell($product, 2)->assertOk();

        $this->assertSame(28.0, $this->remaining($product));
    }

    /**
     * Two purchases into one batch, then a sale spanning both.
     *
     * The first lot is exhausted before the second is touched, and the total is
     * what matters to the shelf.
     */
    public function test_a_sale_spans_two_purchases_of_the_same_batch(): void
    {
        $product = $this->product('SPAN');

        $this->buy($product, 3);
        $this->buy($product, 2);
        $this->assertSame(5.0, $this->remaining($product));

        $this->sell($product, 4)->assertOk();

        $this->assertSame(1.0, $this->remaining($product));
    }

    /** Selling from one batch does not touch another. */
    public function test_selling_one_batch_leaves_the_other_alone(): void
    {
        $product = $this->product('TWOBATCH');

        $this->buy($product, 3, batch: 'B-1');
        $this->buy($product, 4, batch: 'B-2');

        $this->sell($product, 2, batch: 'B-1')->assertOk();

        // Three bought into this batch, two sold.
        $this->assertSame(
            1.0,
            (float) DB::table('purchase_details')
                ->where('stock_id', $this->stockIdFor($product, 'B-1'))
                ->sum('conversionQuantity'),
        );
        $this->assertSame(
            4.0,
            (float) DB::table('purchase_details')
                ->where('stock_id', $this->stockIdFor($product, 'B-2'))
                ->sum('conversionQuantity'),
            'the untouched batch keeps everything',
        );
    }
}

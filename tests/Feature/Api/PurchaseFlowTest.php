<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Support\Api\PurchaseLineBuilder;
use Tests\TestCase;

/**
 * Creating a purchase, the way the app actually does it.
 *
 * The existing coverage exercises one tidy line with a batch number. A shop does
 * not always work that way: plenty of goods carry no batch, the same product
 * arrives in several batches on one invoice, and the same batch is topped up twice.
 *
 * The thing every one of these has to end with is a *sellable* product. Stock is
 * counted from `purchase_details.conversionQuantity`, so a purchase that raises
 * `stocks.productStock` but leaves that column wrong produces a shelf full of goods
 * the till refuses to sell — which looks like the purchase never happened.
 */
class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Business $business;
    private Party $party;
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
            'phone'       => '9000000123',
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

    /** One purchase line, with sensible defaults the app would send. */
    private function line(Product $product, array $overrides = []): array
    {
        return $overrides + [
            'product_id'                => $product->id,
            'quantities'                => 5,
            'productSalePrice'          => 30,
            'productPurchasePrice'      => 20,
            'conversion_purchase_price' => 2,
            'conversion_quantities'     => 50,
            'conversion_value'          => 10,
            'conversion_price'          => 3,
            'total_purchase_price'      => 100,
            'total_price'               => 100,
            'expire_date'               => '2030-01-01',
            'mfg_date'                  => '2026-01-01',
            'upper_selected_unit'       => 1,
            'lower_selected_unit'       => 1,
        ];
    }

    private function purchase(array $lines, array $overrides = [])
    {
        return $this->postJson('/api/v1/purchase', $overrides + [
            'party_id'    => $this->party->id,
            'paidAmount'  => 0,
            'dueAmount'   => 0,
            'totalAmount' => 100,
            'paymentType' => 'Cash',
            'products'    => $lines,
        ]);
    }

    /** What the till will actually let you sell of this product. */
    private function sellable(Product $product): float
    {
        return (float) DB::table('purchase_details')
            ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
            ->where('stocks.product_id', $product->id)
            ->sum('purchase_details.conversionQuantity');
    }

    /**
     * The case a general store hits constantly: goods with no batch number.
     *
     * `stocks` is keyed partly on `batch_no`, and a null there behaves differently
     * from a value in SQL — so this path is not the tested one with the string
     * removed, it is a different path.
     */
    public function test_a_purchase_without_a_batch_number_succeeds(): void
    {
        $product = $this->product('NO-BATCH');

        $this->purchase([$this->line($product)])->assertOk();

        $this->assertDatabaseHas('stocks', [
            'business_id'  => $this->business->id,
            'product_id'   => $product->id,
            'batch_no'     => null,
            'productStock' => 5,
        ]);
    }

    public function test_batch_picker_omits_blank_stock_rows_but_lists_real_batches(): void
    {
        $product = $this->product('BATCH-PICKER');

        \App\Models\Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => null,
        ]);
        \App\Models\Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => '',
        ]);
        \App\Models\Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'CHARGER-B1',
        ]);

        $response = $this->getJson("/api/v1/purchase/batch-details/{$product->id}")
            ->assertOk();

        $this->assertSame(['CHARGER-B1'], collect($response->json('data'))
            ->pluck('batch_no')
            ->all());
    }

    /** And the goods must then be sellable, which is the point of buying them. */
    public function test_a_purchase_leaves_the_product_sellable(): void
    {
        $product = $this->product('SELLABLE');

        $this->purchase([$this->line($product, ['batch_no' => 'B-1'])])->assertOk();

        $this->assertSame(
            50.0,
            $this->sellable($product),
            'a purchase that does not leave stock sellable has not really happened',
        );
    }

    /**
     * The inventory screen and its search use different product-list cache keys.
     * A purchase must invalidate all of them or the unfiltered list keeps showing
     * the old zero stock/price while searching appears to "fix" the product.
     */
    public function test_a_purchase_refreshes_the_cached_product_list_stock_and_price(): void
    {
        $product = $this->product('CACHE-REFRESH');
        $path = '/api/v1/products?with[]=vat&per_page=50&page=1&sort=newest';

        $before = $this->getJson($path)->assertOk();
        $this->assertSame(0, $before->json('data.data.0.stocks.0.productStock') ?? 0);

        $this->purchase([
            $this->line($product, [
                'batch_no' => 'CHARGER-B1',
                'quantities' => 5,
                'conversion_quantities' => 50,
                'productSalePrice' => 70,
            ]),
        ])->assertOk();

        $after = $this->getJson($path)->assertOk();
        $row = collect($after->json('data.data'))
            ->firstWhere('id', $product->id);

        $this->assertNotNull($row);
        $this->assertSame(5.0, (float) data_get($row, 'stocks.0.productStock'));
        $this->assertSame(70.0, (float) data_get($row, 'stocks.0.productSalePrice'));
        $this->assertNotEmpty(data_get($row, 'stocks.0.purchase_details'));
    }

    public function test_a_purchase_without_a_batch_leaves_it_sellable_too(): void
    {
        $product = $this->product('SELLABLE-NB');

        $this->purchase([$this->line($product)])->assertOk();

        $this->assertSame(50.0, $this->sellable($product));
    }

    /**
     * One invoice, the same product in two batches — an ordinary delivery.
     *
     * Each batch is its own stock row and its own lot, because they expire on
     * different dates and FIFO has to tell them apart.
     */
    public function test_the_same_product_in_two_batches_creates_two_lots(): void
    {
        $product = $this->product('TWO-BATCH');

        $this->purchase([
            $this->line($product, ['batch_no' => 'B-1', 'quantities' => 5]),
            $this->line($product, [
                'batch_no'              => 'B-2',
                'quantities'            => 3,
                'conversion_quantities' => 30,
            ]),
        ])->assertOk();

        $this->assertSame(
            2,
            DB::table('stocks')->where('product_id', $product->id)->count(),
        );
        $this->assertSame(80.0, $this->sellable($product));
    }

    /** Topping up a batch that already exists adds to it rather than replacing it. */
    public function test_buying_the_same_batch_again_adds_to_it(): void
    {
        $product = $this->product('TOPUP');

        $this->purchase([$this->line($product, ['batch_no' => 'B-1'])])->assertOk();
        $this->purchase([$this->line($product, ['batch_no' => 'B-1'])])->assertOk();

        $this->assertSame(
            1,
            DB::table('stocks')->where('product_id', $product->id)->count(),
            'the same batch is one stock row, not two',
        );
        $this->assertDatabaseHas('stocks', [
            'product_id'   => $product->id,
            'batch_no'     => 'B-1',
            'productStock' => 10,
        ]);
        $this->assertSame(100.0, $this->sellable($product));
    }

    public function test_topping_up_a_batch_without_dates_preserves_its_existing_dates(): void
    {
        $product = $this->product('DATED-TOPUP');

        $this->purchase([$this->line($product, [
            'batch_no' => 'DATED-B1',
            'mfg_date' => '2026-01-10',
            'expire_date' => '2028-01-10',
        ])])->assertOk();

        DB::transaction(function () use ($product): void {
            PurchaseLineBuilder::stockFor($this->line($product, [
                'batch_no' => 'DATED-B1',
                'mfg_date' => '',
                'expire_date' => '',
            ]), $this->business->id);
        });

        $this->assertDatabaseHas('stocks', [
            'product_id' => $product->id,
            'batch_no' => 'DATED-B1',
            'mfg_date' => '2026-01-10 00:00:00',
            'expire_date' => '2028-01-10 00:00:00',
        ]);

        $this->getJson("/api/v1/purchase/batch-details/{$product->id}/DATED-B1")
            ->assertOk()
            ->assertJsonPath('batch_details.mfg_date', '2026-01-10')
            ->assertJsonPath('batch_details.expire_date', '2028-01-10');
    }

    /** Several different products on one invoice, which is the normal case. */
    public function test_a_multi_product_invoice_stocks_all_of_them(): void
    {
        $a = $this->product('MULTI-A');
        $b = $this->product('MULTI-B');
        $c = $this->product('MULTI-C');

        $this->purchase([
            $this->line($a, ['batch_no' => 'A-1']),
            $this->line($b, ['batch_no' => 'B-1']),
            $this->line($c),
        ])->assertOk();

        foreach ([$a, $b, $c] as $product) {
            $this->assertSame(
                50.0,
                $this->sellable($product),
                "product {$product->productCode} was not left sellable",
            );
        }
    }

    /** A purchase must never touch another shop's product. */
    public function test_another_businesses_product_is_refused(): void
    {
        $other = Business::factory()->create();
        $theirs = Product::create([
            'productName' => 'Theirs',
            'business_id' => $other->id,
            'productCode' => 'THEIRS-1',
        ]);

        $this->purchase([$this->line($theirs)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('products.0.product_id');
    }

    /**
     * A failed line must not leave a half-written purchase behind: the invoice,
     * its lines, and the stock it moved either all happen or none do.
     */
    public function test_a_rejected_purchase_writes_nothing(): void
    {
        $product = $this->product('ATOMIC');

        $before = DB::table('purchases')->count();

        // The split amounts do not add up to what was paid.
        $this->purchase([$this->line($product)], [
            'paidAmount'  => 100,
            'paymentType' => 'Split',
            'cash_amount' => 20,
            'upi_amount'  => 30,
            'card_amount' => 40,
        ])->assertStatus(400);

        $this->assertSame($before, DB::table('purchases')->count());
        $this->assertSame(0.0, $this->sellable($product));
    }
}

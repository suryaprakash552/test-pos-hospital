<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What a purchase records as available to sell.
 *
 * A purchase carries two quantities: `quantities`, the number of strips or boxes
 * bought, and `conversionQuantity`, the same stock counted in the unit a sale
 * draws down — tablets. The second is what the till checks before accepting a
 * sale, so it decides what the shop can actually sell.
 *
 * They have to agree. A client that sends three strips and a remaining quantity
 * of two leaves the shop unable to sell what it is holding, and nothing in the
 * app explains the difference.
 */
class PurchaseQuantityIntegrityTest extends TestCase
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
            'phone'       => '9000000777',
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

    private function buy(Product $product, array $overrides)
    {
        return $this->postJson('/api/v1/purchase', [
            'party_id'    => $this->party->id,
            'paidAmount'  => 0,
            'dueAmount'   => 0,
            'totalAmount' => 100,
            'paymentType' => 'Cash',
            'products'    => [
                $overrides + [
                    'product_id'                => $product->id,
                    'batch_no'                  => 'B-1',
                    'quantities'                => 3,
                    'conversion_quantities'     => 3,
                    'conversion_value'          => 1,
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
                ],
            ],
        ]);
    }

    /** What the till would let the shop sell of this product. */
    private function sellable(Product $product): float
    {
        return (float) DB::table('purchase_details')
            ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
            ->where('stocks.product_id', $product->id)
            ->sum('purchase_details.conversionQuantity');
    }

    /**
     * The reported case: three bought, and three sellable.
     *
     * With no conversion, the two quantities are the same number and any
     * difference between them is a fault.
     */
    public function test_buying_three_leaves_three_sellable(): void
    {
        $product = $this->product('SIMPLE');

        $this->buy($product, [])->assertOk();

        $this->assertSame(3.0, $this->sellable($product));
    }

    /** With a conversion, the sellable figure is in the smaller unit. */
    public function test_a_conversion_is_counted_in_the_unit_a_sale_uses(): void
    {
        $product = $this->product('STRIPS');

        // Three strips of ten tablets.
        $this->buy($product, [
            'quantities'            => 3,
            'conversion_quantities' => 30,
            'conversion_value'      => 10,
        ])->assertOk();

        $this->assertSame(
            30.0,
            $this->sellable($product),
            'three strips of ten is thirty tablets to sell',
        );
    }

    /** @dataProvider bonusConversionProvider */
    public function test_bonus_stock_respects_every_conversion_value(int $conversion): void
    {
        $product = $this->product('BONUS-'.$conversion);

        $this->buy($product, [
            'quantities' => 10,
            'free_quantity' => 5,
            'conversion_quantities' => 15 * $conversion,
            'conversion_value' => $conversion,
        ])->assertOk();

        $this->assertSame((float) (15 * $conversion), $this->sellable($product));
    }

    public static function bonusConversionProvider(): array
    {
        return [[1], [5], [10], [39], [50]];
    }

    /**
     * A second purchase into the same batch adds to it.
     *
     * The same lot number arrives on more than one invoice, and the shop holds
     * the sum.
     */
    public function test_buying_the_same_batch_twice_adds_up(): void
    {
        $product = $this->product('TWICE');

        $this->buy($product, [])->assertOk();
        $this->buy($product, [])->assertOk();

        $this->assertSame(6.0, $this->sellable($product));
    }

    /**
     * The integrity rule the client kept breaking: a remaining quantity that
     * disagrees with what was bought.
     *
     * One code path derived it as quantity x conversion and another read it from
     * a separate editable field, so the same purchase recorded different amounts
     * depending on which saved it. The server should not accept the contradiction
     * whatever the client does.
     */
    public function test_a_contradictory_quantity_is_refused(): void
    {
        $product = $this->product('MISMATCH');

        // Three strips of one, but only two claimed as sellable.
        $response = $this->buy($product, [
            'quantities'            => 3,
            'conversion_quantities' => 2,
            'conversion_value'      => 1,
        ]);

        $this->assertSame(
            422,
            $response->status(),
            'three bought and two sellable cannot both be true',
        );
        $this->assertSame(0.0, $this->sellable($product));
    }

    /** And the same contradiction with a conversion applied. */
    public function test_a_conversion_that_does_not_multiply_out_is_refused(): void
    {
        $product = $this->product('BADCONV');

        // Three strips of ten should be thirty, not twenty.
        $response = $this->buy($product, [
            'quantities'            => 3,
            'conversion_quantities' => 20,
            'conversion_value'      => 10,
        ]);

        $this->assertSame(422, $response->status());
    }

    /**
     * Loose units on top of full strips are legitimate, and the rule is a floor
     * rather than an equality precisely so this still works: three strips of ten
     * plus five loose tablets is thirty-five.
     */
    public function test_loose_units_on_top_of_full_strips_are_allowed(): void
    {
        $product = $this->product('LOOSE');

        $this->buy($product, [
            'quantities'            => 3,
            'conversion_quantities' => 35,
            'conversion_value'      => 10,
        ])->assertOk();

        $this->assertSame(35.0, $this->sellable($product));
    }

    /** A strip cannot contain zero lower units. */
    public function test_a_zero_conversion_is_rejected(): void
    {
        $product = $this->product('ZEROCONV');

        $this->buy($product, [
            'quantities'            => 3,
            'conversion_quantities' => 3,
            'conversion_value'      => 0,
        ])->assertStatus(422);

        $this->assertSame(0.0, $this->sellable($product));
    }
}

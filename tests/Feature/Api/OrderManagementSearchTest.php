<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reorder report behind the purchase-order screen.
 *
 * It answers one question — how much of each product went out over a period — and
 * a shop orders stock against the answer. An inflated figure here means ordering
 * several times what is needed and paying for it.
 *
 * The trap is that a product is bought in batches. `purchase_details` holds one row
 * per lot, so joining it to `sale_details` multiplies every sale row by the number
 * of lots the product has, and the SUM over those rows counts the same sale two or
 * three times.
 */
class OrderManagementSearchTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private int $productId;
    private int $stockId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->productId = DB::table('products')->insertGetId([
            'productName' => 'Paracetamol 650',
            'productCode' => 'PARA-650',
            'business_id' => $this->business->id,
        ]);

        $this->stockId = DB::table('stocks')->insertGetId([
            'business_id'     => $this->business->id,
            'product_id'      => $this->productId,
            'conversionValue' => 10,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->actingAs($this->user);
    }

    /** Records a purchase lot against the shared stock row. */
    private function lot(float $quantity, float $conversionValue = 10): void
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'P-' . uniqid(),
            'totalAmount'   => 0,
            'purchaseDate'  => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('purchase_details')->insert([
            'purchase_id'        => $purchaseId,
            'product_id'         => $this->productId,
            'stock_id'           => $this->stockId,
            'quantities'         => $quantity,
            'conversionQuantity' => $quantity,
            'conversionValue'    => $conversionValue,
            'purchaseUpperLimit' => 0,
            'purchaseLowerLimit' => 0,
        ]);
    }

    /** Records a sale of [quantity] from the shared stock row. */
    private function sell(float $quantity): void
    {
        $saleId = DB::table('sales')->insertGetId([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'S-' . uniqid(),
            'totalAmount'   => 0,
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('sale_details')->insert([
            'sale_id'    => $saleId,
            'product_id' => $this->productId,
            'stock_id'   => $this->stockId,
            'quantities' => $quantity,
        ]);
    }

    private function search()
    {
        return $this->postJson('/api/v1/order-management/search', [
            'from_date' => now()->subMonth()->toDateString(),
            'to_date'   => now()->addDay()->toDateString(),
        ]);
    }

    /** The simple case, to establish the baseline is right. */
    public function test_a_single_lot_reports_what_was_sold(): void
    {
        $this->lot(100);
        $this->sell(30);

        $response = $this->search()->assertOk();

        $this->assertEquals(30, $response->json('data.0.lower_qty'));
    }

    /**
     * The bug: the same product bought in three batches reported three times the
     * quantity sold, because the lot join multiplied every sale row.
     *
     * A shop reading this would order triple what it needed.
     */
    public function test_several_lots_do_not_multiply_what_was_sold(): void
    {
        $this->lot(100);
        $this->lot(50);
        $this->lot(25);

        $this->sell(30);

        $response = $this->search()->assertOk();

        $this->assertEquals(
            30,
            $response->json('data.0.lower_qty'),
            'the product sold 30, however many batches it was bought in',
        );
    }

    public function test_several_sales_across_several_lots_add_up_once(): void
    {
        $this->lot(100);
        $this->lot(50);

        $this->sell(10);
        $this->sell(20);
        $this->sell(5);

        $response = $this->search()->assertOk();

        $this->assertEquals(35, $response->json('data.0.lower_qty'));
    }

    /** The larger unit is the smaller one divided by the conversion. */
    public function test_the_upper_quantity_uses_the_conversion(): void
    {
        $this->lot(100);
        $this->sell(30);

        $response = $this->search()->assertOk();

        // 30 loose units at 10 per pack → 3 packs.
        $this->assertEquals(3, $response->json('data.0.upper_qty'));
    }

    /** A product never sold has nothing to reorder against. */
    public function test_a_product_with_no_sales_is_absent(): void
    {
        $this->lot(100);

        $response = $this->search()->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_another_businesses_sales_are_not_counted(): void
    {
        $this->lot(100);
        $this->sell(30);

        $other = Business::factory()->create();
        $otherSale = DB::table('sales')->insertGetId([
            'business_id'   => $other->id,
            'invoiceNumber' => 'S-OTHER',
            'totalAmount'   => 0,
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        DB::table('sale_details')->insert([
            'sale_id'    => $otherSale,
            'product_id' => $this->productId,
            'stock_id'   => $this->stockId,
            'quantities' => 999,
        ]);

        $response = $this->search()->assertOk();

        $this->assertEquals(30, $response->json('data.0.lower_qty'));
    }
}

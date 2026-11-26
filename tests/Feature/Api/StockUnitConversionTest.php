<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Counting the same stock in strips and in tablets.
 *
 * A product is held in the smaller unit — `conversionQuantity` is tablets — and
 * shown to a shop in the larger one. The conversion is per *lot*: the same
 * medicine arrives in strips of ten on one invoice and strips of fifteen on the
 * next, and both sit against the product.
 *
 * So the strip count is the sum of each lot's own division, not the total tablets
 * divided by any single lot's conversion. Using one lot's figure for all of them
 * is what makes a screen and its PDF disagree about the same product.
 */
class StockUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->productId = DB::table('products')->insertGetId([
            'productName' => 'Foracort 200',
            'productCode' => 'FORA-200',
            'business_id' => $this->business->id,
            'alert_qty'   => 1000,
        ]);

        $this->actingAs($this->user);
    }

    /** A lot of [strips] at [conversion] per strip. */
    private function lot(int $strips, int $conversion, string $batch): void
    {
        $stockId = DB::table('stocks')->insertGetId([
            'business_id'     => $this->business->id,
            'product_id'      => $this->productId,
            'batch_no'        => $batch,
            'conversionValue' => $conversion,
            'productStock'    => $strips,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

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
            'stock_id'           => $stockId,
            'quantities'         => $strips,
            // An unset conversion means the product is not sub-divided, so a
            // strip holds one unit — not none. Multiplying by a literal zero
            // would record a lot holding nothing.
            'conversionQuantity' => $strips * max($conversion, 1),
            'conversionValue'    => $conversion,
            'purchaseUpperLimit' => 0,
            'purchaseLowerLimit' => 0,
        ]);
    }

    private function lowStock()
    {
        return $this->getJson('/api/v1/low-stock-products');
    }

    private function firstRow($response): ?array
    {
        $data = $response->json('data');
        if (is_array($data) && isset($data['data'])) {
            $data = $data['data'];
        }

        return is_array($data) && $data !== [] ? $data[0] : null;
    }

    /** One conversion throughout: the simple case, to fix the baseline. */
    public function test_a_single_conversion_counts_correctly(): void
    {
        $this->lot(3, 10, 'A');

        $row = $this->firstRow($this->lowStock()->assertOk());

        $this->assertNotNull($row);
        $this->assertEquals(30, $row['total_conversion_quantity'], '3 strips of 10');
        $this->assertEquals(3, $row['remaining_upper_quantity']);
    }

    /**
     * Two lots at different conversions.
     *
     * Three strips of ten and two strips of fifteen is sixty tablets and five
     * strips. Dividing the sixty by either lot's conversion gives four or six —
     * both wrong, and wrong in opposite directions.
     */
    public function test_lots_with_different_conversions_add_up_by_lot(): void
    {
        $this->lot(3, 10, 'A');
        $this->lot(2, 15, 'B');

        $row = $this->firstRow($this->lowStock()->assertOk());

        $this->assertNotNull($row);
        $this->assertEquals(
            60,
            $row['total_conversion_quantity'],
            'thirty tablets plus thirty tablets',
        );
        $this->assertEquals(
            5,
            $row['remaining_upper_quantity'],
            'three strips plus two strips, each divided by its own conversion',
        );
    }

    /** A lot bought loose sits alongside one bought in strips. */
    public function test_an_unconverted_lot_counts_as_its_own_units(): void
    {
        $this->lot(2, 10, 'A');
        $this->lot(5, 1, 'LOOSE');

        $row = $this->firstRow($this->lowStock()->assertOk());

        $this->assertEquals(25, $row['total_conversion_quantity']);
        $this->assertEquals(
            7,
            $row['remaining_upper_quantity'],
            'two strips plus five loose units',
        );
    }

    /** A conversion of zero must not divide the stock away. */
    public function test_a_zero_conversion_does_not_erase_the_stock(): void
    {
        $this->lot(4, 0, 'ZERO');

        $row = $this->firstRow($this->lowStock()->assertOk());

        $this->assertNotNull($row);
        $this->assertEquals(4, $row['remaining_upper_quantity']);
    }
}

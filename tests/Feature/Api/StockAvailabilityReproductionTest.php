<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug 15: "the sale screen says no stock when stock exists".
 *
 * Written to reproduce, not to fix. Each case sets up a known holding and then
 * compares the three figures that have to agree:
 *
 *     what the batch rows hold  ==  what the API reports  ==  whether a sale is allowed
 *
 * "No stock when stock exists" is a disagreement between those three, so each
 * case checks all of them rather than only the one the UI happens to read.
 */
class StockAvailabilityReproductionTest extends TestCase
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
     * A batch holding `$lots` separate purchase lots.
     *
     * @param  list<float>  $lots
     */
    private function stockWithLots(array $lots, float $price = 100): Stock
    {
        $product = Product::create([
            'business_id' => $this->business->id,
            'productName' => 'Widget',
            'productSalePrice' => $price,
            'productPurchasePrice' => 60,
        ]);

        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => array_sum($lots),
            'productSalePrice' => $price,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 60 * array_sum($lots),
        ]);

        foreach ($lots as $quantity) {
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
        }

        return $stock->fresh();
    }

    /** What the product list endpoint reports for this batch. */
    private function apiAvailability(Stock $stock): float
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/products')
            ->assertSuccessful();

        foreach ($response->json('data.data') as $product) {
            foreach ($product['stocks'] ?? [] as $row) {
                if ((int) $row['id'] === (int) $stock->id) {
                    return (float) ($row['available_quantity'] ?? 0);
                }
            }
        }

        return -1;
    }

    /** Whether the checkout will actually accept a sale of this size. */
    private function saleAccepted(Stock $stock, float $quantity): bool
    {
        return $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'products' => json_encode([[
                    'stock_id' => $stock->id,
                    'quantities' => $quantity,
                    'lossProfit' => 0,
                ]]),
                'saleDate' => now()->toDateString(),
                'paymentType' => 'cash',
                'totalAmount' => 0,
                'paidAmount' => 100000,
            ])
            ->status() === 200;
    }

    public function test_a_single_lot_reports_and_sells_what_it_holds(): void
    {
        $stock = $this->stockWithLots([50]);

        $this->assertSame(50.0, (float) $stock->available_quantity);
        $this->assertSame(50.0, $this->apiAvailability($stock));
        $this->assertTrue($this->saleAccepted($stock, 50));
    }

    /**
     * The case the accessor's own comment names: a batch built from several lots
     * where the oldest has been drawn down.
     */
    public function test_several_lots_sum_rather_than_reporting_the_first(): void
    {
        $stock = $this->stockWithLots([0.0001, 40]);

        $this->assertEqualsWithDelta(40.0001, (float) $stock->available_quantity, 0.001);
        $this->assertEqualsWithDelta(40.0001, $this->apiAvailability($stock), 0.001);
        $this->assertTrue(
            $this->saleAccepted($stock, 30),
            'A nearly-empty first lot must not make the batch read as empty.',
        );
    }

    public function test_a_genuinely_empty_batch_is_refused(): void
    {
        $stock = $this->stockWithLots([0]);

        $this->assertSame(0.0, (float) $stock->available_quantity);
        $this->assertFalse($this->saleAccepted($stock, 1));
    }

    public function test_availability_falls_as_stock_is_sold(): void
    {
        $stock = $this->stockWithLots([50]);

        $this->assertTrue($this->saleAccepted($stock, 20));

        // The remaining 30 must still be sellable and still reported.
        $this->assertEqualsWithDelta(30.0, $this->apiAvailability($stock), 0.001);
        $this->assertTrue($this->saleAccepted($stock, 30));
    }

    public function test_selling_more_than_the_batch_holds_is_refused(): void
    {
        $stock = $this->stockWithLots([10]);

        $this->assertFalse($this->saleAccepted($stock, 11));
        $this->assertSame(0, Sale::count());
    }

    public function test_decimal_holdings_are_not_rounded_away(): void
    {
        // A strip-and-loose-tablet holding: 2.5 is a real quantity, not noise.
        $stock = $this->stockWithLots([2.5]);

        $this->assertEqualsWithDelta(2.5, (float) $stock->available_quantity, 0.001);
        $this->assertEqualsWithDelta(2.5, $this->apiAvailability($stock), 0.001);
        $this->assertTrue($this->saleAccepted($stock, 2));
    }

    public function test_another_tenants_batch_is_not_reported_as_available(): void
    {
        $stock = $this->stockWithLots([50]);

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->getJson('/api/v1/products')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data.data');

        // And the batch is still fine for its own business.
        $this->assertSame(50.0, $this->apiAvailability($stock));
    }
}

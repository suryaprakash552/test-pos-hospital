<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\StockConversionBackfill;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `stocks.conversionValue` says how many tablets make a strip, and it was never
 * written — every one of the 4,139 rows held NULL. Readers papered over it with
 * a fallback chain ending in **1**, so a batch with no surviving lot rows
 * divided by one and reported tablets as strips: at a conversion of 50, fifty
 * times what is on the shelf.
 */
class StockConversionBackfillTest extends TestCase
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

    private function product(float $conversion): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'productName' => 'SAZOPRIDE-2MG',
            'productSalePrice' => 169,
            'conversionValue' => $conversion,
        ]);
    }

    private function batch(Product $product): Stock
    {
        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 750,
            'productSalePrice' => 169,
        ]);

        // The column starts life NULL, which is the state being fixed.
        DB::table('stocks')->where('id', $stock->id)
            ->update(['conversionValue' => null]);

        return $stock;
    }

    private function lot(Stock $stock, float $conversion): void
    {
        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 100,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $stock->product_id,
            'stock_id' => $stock->id,
            'quantities' => 50,
            'conversionQuantity' => 750,
            'conversionValue' => $conversion,
            'productPurchasePrice' => 100,
            'productSalePrice' => 169,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    private function backfill(): void
    {
        // The class rather than the migration: `RefreshDatabase` has already run
        // every migration by the time a test starts, so re-invoking it is a
        // no-op and would test nothing.
        StockConversionBackfill::run();
    }

    private function stored(Stock $stock): ?string
    {
        return DB::table('stocks')->where('id', $stock->id)->value('conversionValue');
    }

    public function test_the_lot_that_filled_the_batch_wins(): void
    {
        // The product's own value can be edited after the fact; the lot records
        // what was actually bought.
        $product = $this->product(10);
        $stock = $this->batch($product);
        $this->lot($stock, 15);

        $this->backfill();

        $this->assertEquals(15, $this->stored($stock));
    }

    public function test_a_batch_with_no_lots_falls_back_to_the_product(): void
    {
        $product = $this->product(50);
        $stock = $this->batch($product);

        $this->backfill();

        $this->assertEquals(50, $this->stored($stock));
    }

    public function test_no_conversion_anywhere_becomes_one_not_zero(): void
    {
        // Zero would divide by nothing; one is the honest "sold in a single unit".
        $product = $this->product(0);
        $stock = $this->batch($product);

        $this->backfill();

        $this->assertEquals(1, $this->stored($stock));
    }

    public function test_a_value_already_set_is_left_alone(): void
    {
        $product = $this->product(10);
        $stock = $this->batch($product);
        DB::table('stocks')->where('id', $stock->id)
            ->update(['conversionValue' => 20]);
        $this->lot($stock, 15);

        $this->backfill();

        $this->assertEquals(20, $this->stored($stock),
            'the backfill must not overwrite a batch that already has one');
    }
}

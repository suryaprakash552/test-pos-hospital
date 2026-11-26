<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Billing reads a product's price from its stock batch, but the product form saved
 * to the product master — so editing a Sale Price appeared to do nothing, and the
 * old price kept being charged until a new purchase created a fresh batch.
 *
 * Editing the price now cascades onto the batches. What must not change: historical
 * sales, which record what was actually charged on `sale_details.price`.
 */
class ProductPriceCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->product = Product::create([
            'productName'      => 'Paracetamol 500mg',
            'business_id'      => $this->business->id,
            'productSalePrice' => 10,
        ]);
    }

    private function batch(string $batchNo, float $salePrice): Stock
    {
        return Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $this->product->id,
            'batch_no'         => $batchNo,
            'productSalePrice' => $salePrice,
            'productStock'     => 5,
        ]);
    }

    private function update(array $payload)
    {
        return $this->actingAs($this->user)->putJson(
            "/api/v1/products/{$this->product->id}",
            $payload + ['productName' => $this->product->productName]
        );
    }

    public function test_editing_the_sale_price_reaches_the_batches(): void
    {
        $batch = $this->batch('B1', 10);

        $this->update(['productSalePrice' => 25])->assertStatus(200);

        $this->assertSame(25.0, $batch->fresh()->productSalePrice,
            'billing reads this column, so the edit must land here');
    }

    public function test_tax_type_is_editable_after_a_sale_without_rewriting_history(): void
    {
        $batch = $this->batch('TAX1', 10);
        $saleId = DB::table('sales')->insertGetId([
            'business_id' => $this->business->id,
            'totalAmount' => 21, 'vat_amount' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_details')->insert([
            'sale_id' => $saleId, 'product_id' => $this->product->id,
            'stock_id' => $batch->id, 'quantities' => 2, 'price' => 10,
            'tax_rate_snapshot' => 5, 'tax_inclusive_snapshot' => false,
        ]);
        $this->update(['vat_type' => 'inclusive'])->assertOk();
        $this->assertSame('inclusive', $this->product->fresh()->vat_type);
        $this->assertSame(1.0, (float) DB::table('sales')->where('id', $saleId)->value('vat_amount'));
        $this->assertSame(5.0, (float) DB::table('sale_details')->where('sale_id', $saleId)->value('tax_rate_snapshot'));
    }

    public function test_every_batch_of_the_product_is_updated(): void
    {
        $first = $this->batch('B1', 10);
        $second = $this->batch('B2', 12);

        $this->update(['productSalePrice' => 30])->assertStatus(200);

        $this->assertSame(30.0, $first->fresh()->productSalePrice);
        $this->assertSame(30.0, $second->fresh()->productSalePrice);
    }

    public function test_other_products_are_left_alone(): void
    {
        $mine = $this->batch('B1', 10);

        $other = Product::create([
            'productName' => 'Aspirin',
            'business_id' => $this->business->id,
        ]);
        $otherBatch = Stock::create([
            'business_id'      => $this->business->id,
            'product_id'       => $other->id,
            'batch_no'         => 'X1',
            'productSalePrice' => 99,
        ]);

        $this->update(['productSalePrice' => 30])->assertStatus(200);

        $this->assertSame(30.0, $mine->fresh()->productSalePrice);
        $this->assertSame(99.0, $otherBatch->fresh()->productSalePrice);
    }

    /**
     * An edit that does not touch a price must not reset it to zero.
     */
    public function test_prices_absent_from_the_request_are_preserved(): void
    {
        $batch = $this->batch('B1', 10);
        $batch->update(['productWholeSalePrice' => 8, 'productDealerPrice' => 7]);

        $this->update(['productSalePrice' => 20])->assertStatus(200);

        $fresh = $batch->fresh();
        $this->assertSame(20.0, $fresh->productSalePrice);
        $this->assertSame(8.0, $fresh->productWholeSalePrice, 'untouched price must survive');
        $this->assertSame(7.0, $fresh->productDealerPrice, 'untouched price must survive');
    }

    public function test_wholesale_and_dealer_prices_cascade_too(): void
    {
        $batch = $this->batch('B1', 10);

        $this->update([
            'productSalePrice'      => 20,
            'productWholeSalePrice' => 15,
            'productDealerPrice'    => 12,
        ])->assertStatus(200);

        $fresh = $batch->fresh();
        $this->assertSame(15.0, $fresh->productWholeSalePrice);
        $this->assertSame(12.0, $fresh->productDealerPrice);
    }

    /**
     * Variant products price each batch separately, supplied as an array. Flattening
     * them to a single value would destroy genuine per-batch pricing.
     */
    public function test_variant_products_do_not_get_a_flattened_price(): void
    {
        $first = $this->batch('B1', 10);
        $second = $this->batch('B2', 12);

        $this->update([
            'product_type'     => 'variant',
            'productSalePrice' => [40, 50],
        ])->assertStatus(200);

        $this->assertSame(10.0, $first->fresh()->productSalePrice);
        $this->assertSame(12.0, $second->fresh()->productSalePrice);
    }

    /**
     * The regression that matters: repricing must not rewrite what a past sale
     * actually charged, or closed-period profit figures would move.
     */
    public function test_repricing_does_not_alter_recorded_sale_history(): void
    {
        $batch = $this->batch('B1', 10);

        $saleId = DB::table('sales')->insertGetId([
            'business_id' => $this->business->id,
            'totalAmount' => 20,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        DB::table('sale_details')->insert([
            'sale_id'    => $saleId,
            'product_id' => $this->product->id,
            'stock_id'   => $batch->id,
            'quantities' => 2,
            'price'      => 10,
        ]);

        $this->update(['productSalePrice' => 45])->assertStatus(200);

        $this->assertSame(
            10.0,
            (float) DB::table('sale_details')->where('sale_id', $saleId)->value('price'),
            'the price charged on a past sale is history and must not move'
        );
        $this->assertSame(45.0, $batch->fresh()->productSalePrice);
    }

    public function test_another_business_cannot_reprice_the_product(): void
    {
        $batch = $this->batch('B1', 10);

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->putJson("/api/v1/products/{$this->product->id}", [
                'productName'      => 'Paracetamol 500mg',
                'productSalePrice' => 999,
            ])
            ->assertStatus(403);

        $this->assertSame(10.0, $batch->fresh()->productSalePrice);
    }
}

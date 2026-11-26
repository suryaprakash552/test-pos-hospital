<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether a reopened sale still knows a product has a discount.
 *
 * Reported from the counter: "sales hold no discount fields showing, even
 * though the products have discount" and the same on sale edit. The app
 * decides whether to draw the discount inputs on a row from two things —
 * the row's own entered discount, and the *product's* configured discount
 * ceiling (`product_flat_discount` / `product_percent_discount`) — because a
 * row that could never be discounted has no reason to offer the fields.
 *
 * The sale endpoints load the nested product through a column-limited
 * eager load, and that list did not include the discount ceiling. So every
 * row on a reopened sale looked undiscountable, whatever the product was
 * actually configured with, and the inputs never appeared.
 */
class SaleProductDiscountVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sale_list_carries_the_products_configured_discount(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'type' => 'Customer',
        ]);

        $manufacturer = Manufacturer::create([
            'business_id' => $business->id,
            'manufacturerName' => 'Acme Pharma',
        ]);
        $product = Product::create([
            'business_id' => $business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            // The ceiling the counter is meant to see, whether or not this
            // particular sale used it.
            'product_flat_discount' => 15,
            'product_percent_discount' => 5,
            'manufacturer_id' => $manufacturer->id,
            'rack' => 'R-12',
            'shelf' => 'S-3',
        ]);

        $stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 10,
            'conversionValue' => 1,
        ]);

        $sale = Sale::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'saleDate' => now(),
            'totalAmount' => 100,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 1,
            'price' => 100,
            'productPurchasePrice' => 60,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/sales')->assertOk();

        $line = collect($response->json('data.data.0.details'))->first();

        $this->assertNotNull($line, 'the sale must come back with its line');
        $this->assertEquals(15, $line['product']['product_flat_discount']);
        $this->assertEquals(5, $line['product']['product_percent_discount']);
        $this->assertSame('R-12', $line['product']['rack']);
        $this->assertSame('S-3', $line['product']['shelf']);
        $this->assertSame(
            'Acme Pharma',
            $line['product']['manufacturer']['manufacturerName']
        );
    }

    /** The same fix applies to the response a save returns, which the same screen re-reads. */
    public function test_an_updated_sale_still_carries_it(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'type' => 'Customer',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'product_flat_discount' => 15,
        ]);

        $stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = \App\Models\Purchase::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'purchaseDate' => now(),
            'totalAmount' => 6000,
        ]);

        \App\Models\PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 100,
            'conversionQuantity' => 100,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        $line = ['stock_id' => $stock->id, 'quantities' => 1, 'lossProfit' => 0];
        $body = [
            'products' => json_encode([$line]),
            'saleDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'customer_id' => $customer->id,
            'totalAmount' => 0,
            'paidAmount' => 100,
        ];

        $this->actingAs($user)->postJson('/api/v1/sales', $body)->assertSuccessful();
        $sale = Sale::latest('id')->first();

        $response = $this->actingAs($user)
            ->putJson("/api/v1/sales/{$sale->id}", $body)
            ->assertSuccessful();

        $updatedLine = collect($response->json('data.details'))->first();

        $this->assertEquals(15, $updatedLine['product']['product_flat_discount']);
    }
}

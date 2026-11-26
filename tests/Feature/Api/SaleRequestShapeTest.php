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
 * The shape the checkout accepts its line items in.
 *
 * `prepareForValidation` decodes `products` whether it arrives as a JSON string
 * or as an array, but the rule beside it said `string` - so an array was refused
 * by the validator immediately after the request had gone to the trouble of
 * accepting it.
 *
 * An array is the obvious thing for a client to try, because the *purchase*
 * endpoint requires exactly that. Both shapes now work here.
 */
class SaleRequestShapeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
        ]);

        $this->stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 50,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 3000,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 50,
            'conversionQuantity' => 50,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function lines(): array
    {
        return [[
            'stock_id' => $this->stock->id,
            'quantities' => 2,
            'lossProfit' => 0,
        ]];
    }

    private function checkout(mixed $products)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales', [
            'products' => $products,
            'saleDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'totalAmount' => 0,
            'paidAmount' => 200,
        ]);
    }

    public function test_line_items_are_accepted_as_a_json_string(): void
    {
        $this->checkout(json_encode($this->lines()))->assertSuccessful();

        $this->assertSame(200.0, (float) Sale::first()->totalAmount);
    }

    public function test_line_items_are_accepted_as_an_array(): void
    {
        // The shape the purchase endpoint requires, and the one a client would
        // reasonably reach for first.
        $this->checkout($this->lines())->assertSuccessful();

        $this->assertSame(200.0, (float) Sale::first()->totalAmount);
    }

    public function test_both_shapes_price_the_sale_identically(): void
    {
        $this->checkout($this->lines())->assertSuccessful();
        $viaArray = (float) Sale::latest('id')->first()->totalAmount;

        $this->checkout(json_encode($this->lines()))->assertSuccessful();
        $viaString = (float) Sale::latest('id')->first()->totalAmount;

        $this->assertSame($viaArray, $viaString);
    }

    public function test_malformed_json_is_still_refused_with_a_usable_message(): void
    {
        $this->checkout('not json at all')
            ->assertStatus(422)
            ->assertJsonValidationErrors('products_payload');
    }

    public function test_an_empty_line_list_is_still_refused(): void
    {
        $this->checkout([])
            ->assertStatus(422);

        $this->assertSame(0, Sale::count());
    }
}

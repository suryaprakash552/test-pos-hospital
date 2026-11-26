<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who a record belongs to is decided by the session, never by the payload.
 *
 * The `except()` lists guarding these writes dropped every money column and no
 * ownership column, and the explicit `user_id`/`business_id` sat on the right of
 * a `+` union - where PHP never reaches them if the request supplies the same
 * key. So the protection read as present and was not.
 *
 * `MassAssignmentGuardTest` stops the pattern returning; this proves the
 * behaviour end to end for the sale that carried it longest.
 */
class OwnershipTamperingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Customer $customer;
    private Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'due' => 0,
        ]);

        $product = Product::create([
            'business_id' => $this->business->id,
            'productName' => 'Widget',
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
        ]);

        $this->stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B1',
            'productStock' => 100,
            'productSalePrice' => 100,
            'productPurchasePrice' => 60,
            'conversionValue' => 1,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 6000,
        ]);

        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $this->stock->id,
            'quantities' => 100,
            'conversionQuantity' => 100,
            'conversionValue' => 1,
            'productPurchasePrice' => 60,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function checkout(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson('/api/v1/sales', array_merge([
            'products' => json_encode([[
                'stock_id' => $this->stock->id,
                'quantities' => 1,
                'lossProfit' => 0,
            ]]),
            'saleDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'customer_id' => $this->customer->id,
            'totalAmount' => 0,
            'paidAmount' => 100,
        ], $overrides));
    }

    public function test_a_sale_is_attributed_to_the_user_who_made_it(): void
    {
        $someoneElse = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);

        $this->checkout(['user_id' => $someoneElse->id])->assertSuccessful();

        $this->assertSame(
            $this->user->id,
            Sale::first()->user_id,
            'The till operator is whoever is signed in, not whoever the payload names.',
        );
    }

    public function test_a_sale_cannot_be_posted_into_another_business(): void
    {
        $otherBusiness = Business::factory()->create();

        $this->checkout(['business_id' => $otherBusiness->id])->assertSuccessful();

        $this->assertSame(
            (int) $this->business->id,
            (int) Sale::first()->business_id,
        );
    }

    public function test_the_stored_sale_is_visible_to_its_own_business(): void
    {
        $this->checkout()->assertSuccessful();

        // The point of the previous test: a sale written into another tenant
        // would vanish from the shop that took the money.
        $this->actingAs($this->user)
            ->getJson('/api/v1/sales')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data.data');
    }
}

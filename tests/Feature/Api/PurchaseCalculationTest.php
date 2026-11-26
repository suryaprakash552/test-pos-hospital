<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Models\User;
use App\Jobs\SendUserNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PurchaseCalculationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);
    }

    public function test_purchase_create_increments_stock_quantity(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $party = Party::create([
            'name' => 'Supplier A',
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'phone' => '9000000001',
        ]);
        $product = Product::create([
            'productName' => 'Stocked Item',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'STOCK-ITEM-1',
            'productStock' => 0,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/purchase', [
            'party_id' => $party->id,
            'paidAmount' => 0,
            'dueAmount' => 0,
            'totalAmount' => 100,
            'paymentType' => 'Cash',
            'products' => [[
                'product_id' => $product->id,
                'batch_no' => 'B-001',
                'quantities' => 5,
                'productSalePrice' => 30,
                'productPurchasePrice' => 20,
                'conversion_purchase_price' => 2,
                'conversion_quantities' => 50,
                'conversion_value' => 10,
                'conversion_price' => 3,
                'total_purchase_price' => 100,
                'total_price' => 100,
                'expire_date' => '2030-01-01',
                'mfg_date' => '2026-01-01',
                'upper_selected_unit' => 1,
                'lower_selected_unit' => 1,
            ]],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('stocks', [
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B-001',
            'productStock' => 5,
        ]);
    }

    public function test_purchase_create_rejects_invalid_split_payment_total(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $party = Party::create([
            'name' => 'Supplier Split',
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'phone' => '9000000099',
        ]);
        $product = Product::create([
            'productName' => 'Split Item',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'STOCK-SPLIT-1',
            'productStock' => 0,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/purchase', [
            'party_id' => $party->id,
            'paidAmount' => 100,
            'dueAmount' => 0,
            'totalAmount' => 100,
            'paymentType' => 'Split',
            'cash_amount' => 20,
            'upi_amount' => 30,
            'card_amount' => 40,
            'products' => [[
                'product_id' => $product->id,
                'batch_no' => 'B-SPLIT',
                'quantities' => 5,
                'productSalePrice' => 30,
                'productPurchasePrice' => 20,
                'conversion_purchase_price' => 2,
                'conversion_quantities' => 50,
                'conversion_value' => 10,
                'conversion_price' => 3,
                'total_purchase_price' => 100,
                'total_price' => 100,
                'expire_date' => '2030-01-01',
                'mfg_date' => '2026-01-01',
                'upper_selected_unit' => 1,
                'lower_selected_unit' => 1,
            ]],
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Split payment total must equal paid amount');
    }

    public function test_purchase_delete_is_blocked_after_stock_is_consumed(): void
    {
        $this->actingAs($this->user);

        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $party = Party::create([
            'name' => 'Supplier B',
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'phone' => '9000000002',
        ]);
        $product = Product::create([
            'productName' => 'Consumed Item',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'STOCK-ITEM-2',
            'productStock' => 3,
        ]);
        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B-002',
            'productStock' => 3,
        ]);
        $purchase = Purchase::create([
            'party_id' => $party->id,
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'paidAmount' => 0,
            'dueAmount' => 0,
            'totalAmount' => 60,
        ]);
        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 3,
            'conversionQuantity' => 20,
            'conversionValue' => 10,
            'productPurchasePrice' => 20,
            'productSalePrice' => 30,
            'totalPurchasePrice' => 60,
            'totalPrice' => 60,
            'purchaseUpperLimit' => 1,
            'purchaseLowerLimit' => 1,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/purchase/{$purchase->id}");

        // A posted purchase is now protected outright rather than only once
        // some of it has sold: it is the source document the lots descend from,
        // so deleting it would strand every batch and event that cites it.
        $response->assertStatus(409)
            ->assertJsonPath('code', 'PURCHASE_HISTORY_PROTECTED')
            ->assertJsonPath('action', 'REVERSE_SOURCE_PURCHASE');

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'productStock' => 3,
        ]);
        $this->assertDatabaseHas('purchases', [
            'id' => $purchase->id,
        ]);
    }

    /**
     * A purchase carrying lots cannot be deleted, notification or not.
     *
     * This once asserted a clean delete with a notification queued. Deletion of
     * a posted purchase is now refused, so what matters is that the refusal is
     * total: no rows removed and no side effects fired on the way out.
     */
    public function test_purchase_delete_is_refused_and_fires_no_side_effects(): void
    {
        Queue::fake();
        $this->actingAs($this->user);

        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $party = Party::create([
            'name' => 'Supplier C',
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'phone' => '9000000003',
        ]);
        $product = Product::create([
            'productName' => 'Delete Item',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'STOCK-ITEM-3',
            'productStock' => 3,
        ]);
        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'B-003',
            'productStock' => 3,
        ]);
        $purchase = Purchase::create([
            'party_id' => $party->id,
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'paidAmount' => 0,
            'dueAmount' => 0,
            'totalAmount' => 60,
        ]);
        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 3,
            'conversionQuantity' => 30,
            'conversionValue' => 10,
            'productPurchasePrice' => 20,
            'productSalePrice' => 30,
            'totalPurchasePrice' => 60,
            'totalPrice' => 60,
            'purchaseUpperLimit' => 1,
            'purchaseLowerLimit' => 1,
        ]);

        $response = $this->deleteJson("/api/v1/purchase/{$purchase->id}");

        $response->assertStatus(409)
            ->assertJsonPath('code', 'PURCHASE_HISTORY_PROTECTED');

        $this->assertDatabaseHas('purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('purchase_details', ['purchase_id' => $purchase->id]);
        Queue::assertNotPushed(SendUserNotificationJob::class);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "If display [customer/supplier name in the transaction history] that will
 * be very good" - a shop reading a product's inventory history saw "Sale -15
 * ... By Akash" and had no way to tell which customer's bill it was, or
 * which supplier a purchase line came from, without opening the sale or
 * purchase itself.
 *
 * The ledger event has no party column of its own - a return only names the
 * purchase or sale it came from - so this is resolved from the source
 * document each row already points to.
 */
class InventoryHistoryPartyNameTest extends TestCase
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

    private function rowFor(int $productId, string $eventType): ?array
    {
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/products/{$productId}/inventory-history")
            ->assertOk();

        return collect($response->json('data'))->firstWhere('event_type', $eventType);
    }

    public function test_a_sale_names_its_customer(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Pulse Wave Rayachoty',
        ]);
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productStock' => 10,
        ]);
        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 500,
        ]);
        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 10,
            'conversionQuantity' => 10,
            'conversionValue' => 1,
            'productPurchasePrice' => 50,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/sales', [
            'products' => json_encode([[
                'stock_id' => $stock->id,
                'quantities' => 2,
                'price' => 100,
                'lossProfit' => 0,
            ]]),
            'customer_id' => $customer->id,
            'saleDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'paidAmount' => 200,
            'totalAmount' => 0,
        ])->assertStatus(200);

        $row = $this->rowFor($product->id, 'sale_out');

        $this->assertNotNull($row);
        $this->assertSame('Pulse Wave Rayachoty', $row['party_name']);
    }

    public function test_a_purchase_names_its_supplier(): void
    {
        $supplier = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Acme Supplies',
            'type' => 'Supplier',
            'due' => 0,
        ]);
        $product = Product::factory()->create(['business_id' => $this->business->id]);

        $this->actingAs($this->user)->postJson('/api/v1/purchase', [
            'party_id' => $supplier->id,
            'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $product->id,
                'quantities' => 10,
                'productPurchasePrice' => 50,
                'productSalePrice' => 80,
                'conversion_purchase_price' => 50,
                'conversion_quantities' => 10,
                'conversion_value' => 1,
                'conversion_price' => 80,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1,
                'lower_selected_unit' => 1,
                'total_purchase_price' => 500,
                'total_price' => 800,
            ]],
        ])->assertStatus(200);

        $row = $this->rowFor($product->id, 'purchase_created');

        $this->assertNotNull($row);
        $this->assertSame('Acme Supplies', $row['party_name']);
    }

    public function test_a_sale_return_names_the_customer_of_the_original_sale(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Returning Customer',
            'due' => 0,
        ]);
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productStock' => 5,
        ]);

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'invoiceNumber' => 'RET-PN-1',
            'saleDate' => now(),
            'totalAmount' => 400,
            'paidAmount' => 400,
            'dueAmount' => 0,
            'lossProfit' => 160,
            'isPaid' => 1,
            'paymentType' => 'cash',
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'price' => 100,
            'quantities' => 4,
            'lossProfit' => 160,
        ]);

        // The return endpoint restores into a real lot; give it one to land on.
        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'purchaseDate' => now(),
            'totalAmount' => 500,
        ]);
        PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 10,
            'conversionQuantity' => 5,
            'conversionValue' => 1,
            'productPurchasePrice' => 50,
            'productSalePrice' => 100,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/sales-return', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'sale_detail_id' => [$detail->id],
            'return_qty' => [1],
            'return_amount' => [100],
        ])->assertStatus(200);

        $row = $this->rowFor($product->id, 'sale_return_in');

        $this->assertNotNull($row);
        $this->assertSame('Returning Customer', $row['party_name']);
    }

    public function test_a_purchase_return_names_the_supplier_of_the_original_purchase(): void
    {
        $supplier = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Returned-To Supplies',
            'type' => 'Supplier',
            'due' => 0,
        ]);
        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'productStock' => 10,
        ]);

        $purchase = Purchase::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'party_id' => $supplier->id,
            'purchaseDate' => now(),
            'totalAmount' => 500,
            'paidAmount' => 500,
            'dueAmount' => 0,
        ]);

        $detail = PurchaseDetails::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'stock_id' => $stock->id,
            'quantities' => 10,
            'conversionQuantity' => 10,
            'conversionValue' => 1,
            'productPurchasePrice' => 50,
            'productSalePrice' => 80,
            'purchaseLowerLimit' => 0,
            'purchaseUpperLimit' => 0,
        ]);

        $this->actingAs($this->user)->postJson('/api/v1/purchases-return', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'purchase_detail_id' => [$detail->id],
            'return_qty' => [2],
            'return_amount' => [100],
        ])->assertStatus(200);

        $row = $this->rowFor($product->id, 'purchase_return_out');

        $this->assertNotNull($row);
        $this->assertSame('Returned-To Supplies', $row['party_name']);
    }
}

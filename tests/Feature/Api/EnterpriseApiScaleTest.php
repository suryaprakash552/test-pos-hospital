<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Stock;
use App\Models\User;
use App\Models\Category;
use App\Models\PaymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseApiScaleTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private Business $otherBusiness;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->otherBusiness = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);
    }

    public function test_customer_index_is_paginated_and_business_scoped(): void
    {
        Customer::insert($this->customerRows(120, $this->business->id, 'customer'));
        Customer::insert($this->customerRows(5, $this->otherBusiness->id, 'other-customer'));

        $response = $this->actingAs($this->user)->getJson('/api/v1/customers?per_page=500');

        $response->assertOk()
            ->assertJsonPath('data.per_page', 100)
            ->assertJsonPath('data.total', 120)
            ->assertJsonCount(100, 'data.data');
    }

    public function test_party_index_is_paginated_and_business_scoped(): void
    {
        Party::insert($this->partyRows(115, $this->business->id, 'party'));
        Party::insert($this->partyRows(7, $this->otherBusiness->id, 'other-party'));

        $response = $this->actingAs($this->user)->getJson('/api/v1/parties?per_page=500');

        $response->assertOk()
            ->assertJsonPath('data.per_page', 100)
            ->assertJsonPath('data.total', 115)
            ->assertJsonCount(100, 'data.data');
    }

    public function test_purchase_index_is_always_business_scoped_for_owner_role(): void
    {
        $party = Party::create([
            'name' => 'Supplier A',
            'business_id' => $this->business->id,
            'type' => 'Supplier',
        ]);
        $otherParty = Party::create([
            'name' => 'Supplier B',
            'business_id' => $this->otherBusiness->id,
            'type' => 'Supplier',
        ]);

        Purchase::insert($this->purchaseRows(3, $this->business->id, $this->user->id, $party->id, 'INV-A'));
        Purchase::insert($this->purchaseRows(4, $this->otherBusiness->id, $this->user->id, $otherParty->id, 'INV-B'));

        $response = $this->actingAs($this->user)->getJson('/api/v1/purchase?per_page=500');

        $response->assertOk()
            ->assertJsonPath('data.per_page', 100)
            ->assertJsonPath('data.total', 3)
            ->assertJsonCount(3, 'data.data');
    }

    public function test_purchase_order_stock_filters_use_correct_range_and_pagination(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        foreach (range(1, 3) as $index) {
            Product::create([
                'business_id' => $this->business->id,
                'category_id' => $category->id,
                'productName' => "Order Product {$index}",
                'productCode' => "ORDER-PRODUCT-{$index}",
                'productStock' => 20,
                'alert_qty' => 5,
                'conversionValue' => 1,
            ]);
        }

        $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-order?available_stock_to=0&per_page=2')
            ->assertOk()
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.total', 3)
            ->assertJsonCount(2, 'data.data');

        $this->actingAs($this->user)
            ->getJson('/api/v1/purchase-order?available_stock_from=1')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_customer_due_collection_rejects_cross_tenant_customer_and_payment_type(): void
    {
        $otherCustomer = Customer::create([
            'name' => 'Other Customer',
            'business_id' => $this->otherBusiness->id,
            'type' => 'Customer',
            'due' => 100,
        ]);
        $otherPaymentType = PaymentType::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Other Cash',
            'status' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-dues', [
                'customer_id' => $otherCustomer->id,
                'payment_type_id' => $otherPaymentType->id,
                'paymentDate' => '30/07/2026',
                'payDueAmount' => 10,
                'cash_amount' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'payment_type_id']);

        $this->assertDatabaseMissing('customer_due_collects', [
            'customer_id' => $otherCustomer->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_customer_due_collection_cannot_create_negative_balance(): void
    {
        $customer = Customer::create([
            'name' => 'Tenant Customer',
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'due' => 25,
        ]);
        $paymentType = PaymentType::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'status' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-dues', [
                'customer_id' => $customer->id,
                'payment_type_id' => $paymentType->id,
                'paymentDate' => '30/07/2026',
                'payDueAmount' => 30,
                'cash_amount' => 30,
            ])
            ->assertStatus(400)
            ->assertJsonPath('message', 'Customer due is 25');

        $this->assertSame(25.0, $customer->fresh()->due);
    }

    public function test_customer_update_cannot_cross_business_boundary(): void
    {
        $customer = Customer::create([
            'name' => 'Other Customer',
            'business_id' => $this->otherBusiness->id,
            'type' => 'Customer',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'Blocked',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('customers', [
            'id' => $customer->id,
            'name' => 'Blocked',
        ]);
    }

    public function test_purchase_rejects_cross_business_party_and_product_ids(): void
    {
        $category = Category::factory()->create(['business_id' => $this->otherBusiness->id]);
        $party = Party::create([
            'name' => 'Other Supplier',
            'business_id' => $this->otherBusiness->id,
            'type' => 'Supplier',
        ]);
        $product = Product::create([
            'productName' => 'Other Product',
            'business_id' => $this->otherBusiness->id,
            'category_id' => $category->id,
            'productCode' => 'OTHER-PRODUCT-1',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/purchase', [
            'party_id' => $party->id,
            'products' => [[
                'product_id' => $product->id,
            ]],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['party_id', 'products.0.product_id']);
    }

    public function test_sale_rejects_cross_business_customer_id(): void
    {
        $customer = Customer::create([
            'name' => 'Other Customer',
            'business_id' => $this->otherBusiness->id,
            'type' => 'Customer',
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/sales', [
            'customer_id' => $customer->id,
            'products' => '[]',
            'paymentType' => 'cash',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);
    }

    public function test_sale_rejects_malformed_empty_and_duplicate_product_lines(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'products' => 'not-json',
                'totalAmount' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_payload']);

        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $product = Product::create([
            'productName' => 'Tenant Product',
            'business_id' => $this->business->id,
            'category_id' => $category->id,
            'productCode' => 'TENANT-SALE-1',
        ]);
        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'batch_no' => 'TENANT-BATCH-1',
            'productStock' => 10,
            'conversionValue' => 1,
        ]);
        $line = [
            'stock_id' => $stock->id,
            'quantities' => 1,
            'price' => 10,
            'conversion_price' => 10,
            'lossProfit' => 1,
        ];

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'products' => json_encode([$line, $line], JSON_THROW_ON_ERROR),
                'totalAmount' => 20,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['products_payload.1.stock_id']);
    }

    public function test_sale_rejects_cross_tenant_stock_and_non_positive_quantity(): void
    {
        $category = Category::factory()->create(['business_id' => $this->otherBusiness->id]);
        $product = Product::create([
            'productName' => 'Other Stock Product',
            'business_id' => $this->otherBusiness->id,
            'category_id' => $category->id,
            'productCode' => 'OTHER-SALE-STOCK-1',
        ]);
        $stock = Stock::create([
            'business_id' => $this->otherBusiness->id,
            'product_id' => $product->id,
            'batch_no' => 'OTHER-BATCH-1',
            'productStock' => 10,
            'conversionValue' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'products' => json_encode([[
                    'stock_id' => $stock->id,
                    'quantities' => 0,
                    'price' => 10,
                    'lossProfit' => 1,
                ]], JSON_THROW_ON_ERROR),
                'totalAmount' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'products_payload.0.stock_id',
                'products_payload.0.quantities',
            ]);
    }

    public function test_staff_cannot_bypass_sales_and_purchase_api_permissions(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
            'visibility' => [
                'sales' => ['read' => '0', 'create' => '0'],
                'purchases' => ['read' => '0', 'create' => '0'],
            ],
        ]);

        $this->actingAs($staff)->getJson('/api/v1/sales')->assertForbidden();
        $this->actingAs($staff)->getJson('/api/v1/purchase')->assertForbidden();
        $this->actingAs($staff)
            ->postJson('/api/v1/sales', [])
            ->assertForbidden();
        $this->actingAs($staff)
            ->postJson('/api/v1/purchase', [])
            ->assertForbidden();
    }

    public function test_customer_update_recalculates_opening_due_and_wallet_safely(): void
    {
        $customer = Customer::create([
            'name' => 'Balance Customer',
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'due' => 50,
            'wallet' => 0,
            'opening_balance' => 50,
            'opening_balance_type' => 'due',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'Balance Customer',
            'type' => 'Customer',
            'opening_balance' => 20,
            'opening_balance_type' => 'advance',
        ]);

        $response->assertOk();
        $customer->refresh();

        $this->assertSame(0.0, (float) $customer->due);
        $this->assertSame(20.0, (float) $customer->wallet);
    }

    public function test_party_update_recalculates_opening_due_and_wallet_safely(): void
    {
        $party = Party::create([
            'name' => 'Balance Supplier',
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'due' => 0,
            'wallet' => 40,
            'opening_balance' => 40,
            'opening_balance_type' => 'advance',
        ]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/parties/{$party->id}", [
            'name' => 'Balance Supplier',
            'type' => 'Supplier',
            'opening_balance' => 15,
            'opening_balance_type' => 'due',
        ]);

        $response->assertOk();
        $party->refresh();

        $this->assertSame(15.0, (float) $party->due);
        $this->assertSame(0.0, (float) $party->wallet);
    }

    private function customerRows(int $count, int $businessId, string $prefix): array
    {
        return collect(range(1, $count))->map(fn ($index) => [
            'name' => "{$prefix}-{$index}",
            'type' => 'Customer',
            'phone' => "{$businessId}100{$index}",
            'business_id' => $businessId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();
    }

    private function partyRows(int $count, int $businessId, string $prefix): array
    {
        return collect(range(1, $count))->map(fn ($index) => [
            'name' => "{$prefix}-{$index}",
            'type' => 'Supplier',
            'phone' => "{$businessId}200{$index}",
            'business_id' => $businessId,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();
    }

    private function purchaseRows(int $count, int $businessId, int $userId, int $partyId, string $prefix): array
    {
        return collect(range(1, $count))->map(fn ($index) => [
            'invoiceNumber' => "{$prefix}-{$index}",
            'business_id' => $businessId,
            'user_id' => $userId,
            'party_id' => $partyId,
            'paidAmount' => 0,
            'dueAmount' => 0,
            'totalAmount' => 10,
            'purchaseDate' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();
    }
}

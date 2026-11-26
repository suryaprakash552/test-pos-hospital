<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_can_be_created_without_authenticated_user_for_queue_jobs(): void
    {
        $business = Business::factory()->create();
        $category = Category::factory()->create(['business_id' => $business->id]);
        $product = Product::create([
            'productName' => 'Queued Import Product',
            'business_id' => $business->id,
            'category_id' => $category->id,
            'productCode' => 'QUEUE-STOCK-1',
        ]);

        auth()->logout();

        $stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_no' => 'QUEUE-BATCH-1',
            'productStock' => 10,
        ]);

        $this->assertNull($stock->branch_id);
        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'productStock' => 10,
        ]);
    }

    public function test_sale_and_purchase_preserve_explicit_invoice_numbers(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user);

        $sale = Sale::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'invoiceNumber' => 'SALE-CUSTOM-1',
            'totalAmount' => 10,
        ]);
        $purchase = Purchase::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'invoiceNumber' => 'PURCHASE-CUSTOM-1',
            'totalAmount' => 10,
        ]);

        $this->assertSame('SALE-CUSTOM-1', $sale->invoiceNumber);
        $this->assertSame('PURCHASE-CUSTOM-1', $purchase->invoiceNumber);
    }

    public function test_visible_to_scope_limits_staff_records(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'staff',
        ]);
        $owner = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);

        Sale::create([
            'business_id' => $business->id,
            'user_id' => $staff->id,
            'totalAmount' => 10,
        ]);
        Sale::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'totalAmount' => 20,
        ]);

        $this->assertSame(1, Sale::query()->visibleTo($staff)->count());
        $this->assertSame(2, Sale::query()->visibleTo($owner)->count());
    }
}

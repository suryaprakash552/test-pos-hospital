<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One product's sale history, bounded and counted correctly.
 *
 * Two defects lived here. `product_id` was optional, so omitting it loaded
 * every `sale_details` row belonging to the business and mapped them all in
 * PHP — on a shop with thousands of invoices, a request that never returns,
 * reachable by leaving a parameter off. And `total_customers` was the count of
 * rows on the page, so one customer buying the same product ten times read as
 * ten customers.
 */
class ProductReportBoundsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);
        $this->product = Product::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $this->actingAs($this->user);
    }

    private function saleOf(Customer $customer, int $lines = 1): void
    {
        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'invoiceNumber' => 'PR-'.uniqid(),
            'saleDate' => now(),
            'totalAmount' => 100 * $lines,
        ]);

        $stock = Stock::factory()->create([
            'business_id' => $this->business->id,
            'product_id' => $this->product->id,
        ]);

        for ($i = 0; $i < $lines; $i++) {
            SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $this->product->id,
                'stock_id' => $stock->id,
                'price' => 100,
                'quantities' => 1,
                'lossProfit' => 0,
            ]);
        }
    }

    private function customer(string $name): Customer
    {
        return Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => $name,
        ]);
    }

    /** Leaving the parameter off used to scan the whole tenant. */
    public function test_a_product_is_required(): void
    {
        $this->getJson('/api/v1/product-report')->assertStatus(422);
    }

    public function test_another_businesss_product_is_refused(): void
    {
        $other = Product::factory()->create([
            'business_id' => Business::factory()->create()->id,
        ]);

        $this->getJson('/api/v1/product-report?product_id='.$other->id)
            ->assertStatus(422);
    }

    /** One customer buying repeatedly is one customer, not one per row. */
    public function test_customers_are_counted_distinctly(): void
    {
        $regular = $this->customer('Regular Buyer');
        $this->saleOf($regular);
        $this->saleOf($regular);
        $this->saleOf($regular);

        $response = $this->getJson('/api/v1/product-report?product_id='.$this->product->id)
            ->assertOk();

        $this->assertSame(1, $response->json('total_customers'));
        $this->assertSame(3, $response->json('total_rows'));
    }

    public function test_two_customers_are_counted_as_two(): void
    {
        $this->saleOf($this->customer('First'));
        $this->saleOf($this->customer('Second'));

        $this->assertSame(
            2,
            $this->getJson('/api/v1/product-report?product_id='.$this->product->id)
                ->assertOk()
                ->json('total_customers'),
        );
    }

    /** The response is a page, and says so. */
    public function test_the_history_is_paginated(): void
    {
        $customer = $this->customer('Bulk Buyer');
        for ($i = 0; $i < 5; $i++) {
            $this->saleOf($customer);
        }

        $response = $this->getJson(
            '/api/v1/product-report?product_id='.$this->product->id.'&per_page=2'
        )->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));

        // Totals span the whole history, not the page in hand.
        $this->assertSame(5, $response->json('total_rows'));
    }

    /** `data` stays a flat list, which is what the app reads. */
    public function test_the_payload_shape_is_unchanged(): void
    {
        $this->saleOf($this->customer('Someone'));

        $response = $this->getJson('/api/v1/product-report?product_id='.$this->product->id)
            ->assertOk();

        $this->assertIsArray($response->json('data'));
        $this->assertArrayHasKey('invoice_number', $response->json('data')[0]);
        $this->assertArrayHasKey('customer_name', $response->json('data')[0]);
    }

    public function test_a_date_range_narrows_the_history(): void
    {
        $customer = $this->customer('Buyer');
        $this->saleOf($customer);

        Sale::latest('id')->first()->update(['saleDate' => now()->subYear()]);

        $inRange = $this->getJson(
            '/api/v1/product-report?product_id='.$this->product->id
            .'&from_date='.now()->subDays(7)->toDateString()
        )->assertOk();

        $this->assertSame(0, $inRange->json('total_rows'));
    }
}

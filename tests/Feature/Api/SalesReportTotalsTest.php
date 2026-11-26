<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sales report's headline figures.
 *
 * The list is paginated, and the app had nothing else to work from, so it
 * folded over the rows it happened to hold and called that the total. On live
 * data that read 96,123 against a real 3,782,660 across 929 sales — and it
 * looked correct whenever a filter narrowed the report to a single page, which
 * is exactly when a wrong total is hardest to notice.
 *
 * The totals are now computed over the filtered query, before paging.
 */
class SalesReportTotalsTest extends TestCase
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

    private function sale(float $total, float $paid): Sale
    {
        $customer = Customer::factory()->create(['business_id' => $this->business->id]);

        return Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $customer->id,
            'saleDate' => today()->toDateString(),
            'totalAmount' => $total,
            'paidAmount' => $paid,
            'dueAmount' => $total - $paid,
        ]);
    }

    private function totals(array $query = []): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/sales?'.http_build_query($query))
            ->assertStatus(200)
            ->json('totals');
    }

    public function test_the_total_covers_every_page_not_just_the_first(): void
    {
        // More sales than fit one page.
        for ($i = 0; $i < 60; $i++) {
            $this->sale(100, 100);
        }

        $totals = $this->totals(['per_page' => 10]);

        $this->assertSame(60, (int) $totals['count']);
        $this->assertSame(6000.0, (float) $totals['total'],
            'a page of ten must not decide the report total');
    }

    public function test_received_and_due_are_reported_separately(): void
    {
        $this->sale(1000, 400);
        $this->sale(500, 500);

        $totals = $this->totals();

        $this->assertSame(1500.0, (float) $totals['total']);
        $this->assertSame(900.0, (float) $totals['received']);
        $this->assertSame(600.0, (float) $totals['due']);
    }

    /**
     * The figures have to follow the filter, or the headline describes a
     * different report from the list beneath it.
     */
    public function test_the_totals_respect_an_active_filter(): void
    {
        $this->sale(90000, 0);
        $this->sale(100, 100);
        $this->sale(200, 200);

        $totals = $this->totals(['min_amount' => 50000]);

        $this->assertSame(1, (int) $totals['count']);
        $this->assertSame(90000.0, (float) $totals['total']);
    }

    public function test_a_report_with_nothing_in_it_totals_zero(): void
    {
        $this->sale(100, 100);

        $totals = $this->totals(['min_amount' => 999999]);

        $this->assertSame(0, (int) $totals['count']);
        $this->assertSame(0.0, (float) $totals['total']);
        $this->assertSame(0.0, (float) $totals['due']);
    }

    public function test_another_businesses_sales_are_not_counted(): void
    {
        $other = Business::factory()->create();
        $otherUser = User::factory()->create([
            'business_id' => $other->id,
            'role' => 'shop-owner',
        ]);
        $otherCustomer = Customer::factory()->create(['business_id' => $other->id]);

        Sale::create([
            'business_id' => $other->id,
            'user_id' => $otherUser->id,
            'customer_id' => $otherCustomer->id,
            'saleDate' => today()->toDateString(),
            'totalAmount' => 5000,
            'paidAmount' => 5000,
            'dueAmount' => 0,
        ]);

        $this->sale(100, 100);

        $this->assertSame(100.0, (float) $this->totals()['total']);
    }
}

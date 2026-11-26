<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sales report's customer filters.
 *
 * Relationship was written as two branches — `$phone && $relationship` and
 * `$phone && !$relationship` — with nothing covering a relationship on its own.
 * Filtering by relationship alone therefore returned every sale, which reads as
 * "the filter does nothing" rather than as an error.
 */
class SaleReportFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;
    protected Customer $mother;
    protected Customer $father;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->mother = Customer::create([
            'business_id'  => $this->business->id,
            'name'         => 'Asha',
            'phone'        => '9000000001',
            'type'         => 'Customer',
            'relationship' => 'Mother',
        ]);

        $this->father = Customer::create([
            'business_id'  => $this->business->id,
            'name'         => 'Ravi',
            'phone'        => '9000000002',
            'type'         => 'Customer',
            'relationship' => 'Father',
        ]);

        // The business factory seeds demo sales; clear them so the counts below
        // describe this fixture rather than the seed.
        Sale::where('business_id', $this->business->id)->delete();

        $this->sale($this->mother, 100);
        $this->sale($this->mother, 200);
        $this->sale($this->father, 300);
    }

    private function sale(?Customer $customer, float $total): Sale
    {
        return Sale::create([
            'business_id'   => $this->business->id,
            'user_id'       => $this->owner->id,
            // `sales.party_id` was renamed to `customer_id`; the `party()`
            // relation is the legacy alias for the same column.
            'customer_id'   => $customer?->id,
            'invoiceNumber' => 'INV-' . uniqid(),
            'totalAmount'   => $total,
            'dueAmount'     => 0,
            'paidAmount'    => $total,
            'saleDate'      => now(),
        ]);
    }

    private function report(array $query): array
    {
        return $this->actingAs($this->owner)
            ->getJson('/api/v1/sales?' . http_build_query($query))
            ->assertOk()
            // `data` is the paginator envelope; the rows are one level down.
            ->json('data.data');
    }

    public function test_customer_id_filter_returns_only_that_customers_sales(): void
    {
        $rows = $this->report(['party_id' => $this->mother->id, 'per_page' => 20]);

        $this->assertCount(2, $rows);
        $this->assertSame(
            [$this->mother->id, $this->mother->id],
            array_column($rows, 'customer_id')
        );
    }

    public function test_relationship_alone_filters_the_report(): void
    {
        // The defect: this returned all three sales.
        $data = $this->report(['relationship' => 'Mother']);

        $this->assertCount(2, $data);
    }

    public function test_a_relationship_with_no_sales_returns_nothing(): void
    {
        $this->assertCount(0, $this->report(['relationship' => 'Brother']));
    }

    public function test_phone_alone_still_filters(): void
    {
        $this->assertCount(1, $this->report(['phone' => '9000000002']));
    }

    public function test_phone_and_relationship_together_still_narrow(): void
    {
        // Both given and consistent: the father's one sale.
        $this->assertCount(1, $this->report([
            'phone'        => '9000000002',
            'relationship' => 'Father',
        ]));
    }

    public function test_a_contradictory_pair_returns_nothing(): void
    {
        // Guards the rewrite: two independent EXISTS clauses must still AND
        // rather than OR. If they ORed, this would return three sales.
        $this->assertCount(0, $this->report([
            'phone'        => '9000000002',
            'relationship' => 'Mother',
        ]));
    }

    public function test_no_filter_returns_every_sale(): void
    {
        $this->assertCount(3, $this->report([]));
    }

    public function test_guest_name_finds_fast_cash_sales_without_a_customer_row(): void
    {
        $guest = $this->sale(null, 450);

        $rows = $this->report(['name' => 'Guest']);

        $this->assertCount(1, $rows);
        $this->assertSame($guest->id, $rows[0]['id']);
        $this->assertNull($rows[0]['customer_id']);
    }
}

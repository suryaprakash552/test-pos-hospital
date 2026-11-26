<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerDueCollect;
use App\Models\PaymentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finding a due collection.
 *
 * The list is one row per payment taken and grows for as long as the shop
 * trades, and it had no filters at all - the only way to find a collection was
 * to scroll past every other one.
 *
 * The visibility rule is deliberately unchanged and pinned here: every staff
 * member sees every collection for the business, because dues are collected by
 * whoever is on the counter rather than only by whoever raised the sale.
 */
class DueListFilterTest extends TestCase
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
            'role' => 'shop-owner',
        ]);
    }

    private function collection(string $name, string $phone, string $date): CustomerDueCollect
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => $name,
            'phone' => $phone,
            'due' => 0,
        ]);

        $paymentType = PaymentType::create([
            'business_id' => $this->business->id,
            'name' => 'Cash',
            'status' => 1,
        ]);

        return CustomerDueCollect::create([
            'business_id' => $this->business->id,
            'customer_id' => $customer->id,
            'user_id' => $this->user->id,
            'payment_type_id' => $paymentType->id,
            'paymentDate' => $date,
            'payDueAmount' => 100,
            'totalDue' => 500,
            'dueAmountAfterPay' => 400,
        ]);
    }

    /** @param array<string, mixed> $query */
    private function names(array $query = []): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/customer-dues?' . http_build_query($query))
            ->assertSuccessful();

        return array_map(
            fn ($row) => $row['customer']['name'] ?? null,
            $response->json('data'),
        );
    }

    public function test_unfiltered_returns_every_collection(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');
        $this->collection('Bharat Rao', '9990002', '2026-07-20');

        $this->assertCount(2, $this->names());
    }

    public function test_it_searches_by_customer_name(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');
        $this->collection('Bharat Rao', '9990002', '2026-07-20');

        $this->assertSame(['Asha Menon'], $this->names(['search' => 'Asha']));
    }

    public function test_the_name_search_matches_partially_and_ignores_case(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');

        $this->assertSame(['Asha Menon'], $this->names(['search' => 'menon']));
    }

    public function test_the_same_box_searches_by_phone(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');
        $this->collection('Bharat Rao', '9990002', '2026-07-20');

        // One box for both: a cashier has a name or a number in front of them and
        // should not have to say which it is.
        $this->assertSame(['Bharat Rao'], $this->names(['search' => '9990002']));
    }

    public function test_it_filters_by_collection_date(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');
        $this->collection('Bharat Rao', '9990002', '2026-07-20');

        $this->assertSame(
            ['Bharat Rao'],
            $this->names(['from_date' => '2026-07-15', 'to_date' => '2026-07-31']),
        );
    }

    public function test_the_date_boundaries_are_inclusive(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');

        $this->assertCount(
            1,
            $this->names(['from_date' => '2026-07-10', 'to_date' => '2026-07-10']),
        );
    }

    public function test_search_and_date_combine(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');
        $this->collection('Asha Menon', '9990003', '2026-07-25');

        $this->assertCount(
            1,
            $this->names(['search' => 'Asha', 'from_date' => '2026-07-20']),
        );
    }

    public function test_a_search_matching_nothing_returns_nothing(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');

        $this->assertSame([], $this->names(['search' => 'Nobody']));
    }

    public function test_every_staff_member_sees_every_collection(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');

        $otherStaff = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);

        $names = array_map(
            fn ($row) => $row['customer']['name'] ?? null,
            $this->actingAs($otherStaff)
                ->getJson('/api/v1/customer-dues')
                ->assertSuccessful()
                ->json('data'),
        );

        $this->assertSame(
            ['Asha Menon'],
            $names,
            'A due raised by someone else is still collectable debt.',
        );
    }

    public function test_another_businesses_collections_are_never_returned(): void
    {
        $this->collection('Asha Menon', '9990001', '2026-07-10');

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->assertSame(
            [],
            $this->actingAs($outsider)
                ->getJson('/api/v1/customer-dues')
                ->assertSuccessful()
                ->json('data'),
        );
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Due List screens' "customer added from/to" date filter had nowhere to
 * land: `PartyController::index` and `CustomerController::index` accepted no
 * `from_date`/`to_date` at all, so the app fetched one unfiltered page and
 * searched only within it. A party older than the 50 most-recently-created
 * was invisible to the filter no matter what range was picked — the filter
 * looked broken, but there was no filter to be broken.
 */
class PartyCustomerDateFilterTest extends TestCase
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

    private function partyOn(string $date): Party
    {
        $party = Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => "Party {$date}",
        ]);
        $party->forceFill(['created_at' => $date])->save();

        return $party;
    }

    private function customerOn(string $date): Customer
    {
        $customer = Customer::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => "Customer {$date}",
        ]);
        $customer->forceFill(['created_at' => $date])->save();

        return $customer;
    }

    public function test_parties_can_be_filtered_by_the_date_they_were_added(): void
    {
        $old = $this->partyOn('2026-01-05');
        $inRange = $this->partyOn('2026-06-15');
        $future = $this->partyOn('2026-12-20');

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/parties?from_date=2026-06-01&to_date=2026-06-30'
        )->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains($inRange->name));
        $this->assertFalse($names->contains($old->name));
        $this->assertFalse($names->contains($future->name));
    }

    public function test_an_old_party_past_the_default_page_is_still_reachable(): void
    {
        // One older than the 50-row default page, plus 50 newer ones ahead of
        // it in the `->latest()` ordering — exactly the shape that made the
        // date filter look broken before it had a query to run against.
        $target = $this->partyOn('2026-01-01');
        for ($i = 0; $i < 50; $i++) {
            $this->partyOn('2026-08-0'.($i % 9 + 1));
        }

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/parties?from_date=2026-01-01&to_date=2026-01-01&per_page=100'
        )->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains($target->name));
    }

    public function test_customers_can_be_filtered_by_the_date_they_were_added(): void
    {
        $old = $this->customerOn('2026-01-05');
        $inRange = $this->customerOn('2026-06-15');

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/customers?from_date=2026-06-01&to_date=2026-06-30'
        )->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertTrue($names->contains($inRange->name));
        $this->assertFalse($names->contains($old->name));
    }

    public function test_the_date_filter_does_not_cross_businesses(): void
    {
        $other = Business::factory()->create();
        $otherParty = Party::create([
            'business_id' => $other->id,
            'type' => 'Supplier',
            'name' => 'Other business party',
        ]);
        $otherParty->forceFill(['created_at' => '2026-06-15'])->save();

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/parties?from_date=2026-06-01&to_date=2026-06-30'
        )->assertSuccessful();

        $names = collect($response->json('data.data'))->pluck('name');

        $this->assertFalse($names->contains('Other business party'));
    }

    public function test_no_date_filter_returns_everything_as_before(): void
    {
        $this->partyOn('2026-01-05');
        $this->partyOn('2026-06-15');

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/parties')
            ->assertSuccessful();

        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_party_picker_can_request_only_suppliers_and_search_them(): void
    {
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Customer',
            'name' => 'Apollo Patient',
        ]);
        $supplier = Party::create([
            'business_id' => $this->business->id,
            'type' => 'Customer, Supplier',
            'name' => 'Apollo Distributor',
            'phone' => '9849300000',
        ]);
        Party::create([
            'business_id' => $this->business->id,
            'type' => 'Supplier',
            'name' => 'Different Distributor',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/parties?type=Supplier&search=Apollo&per_page=20'
        )->assertSuccessful();

        $this->assertSame([$supplier->id], collect($response->json('data.data'))->pluck('id')->all());
    }
}

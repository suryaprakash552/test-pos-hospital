<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a contact without changing their phone number.
 *
 * Reported from the shop floor as "The phone has already been taken." when
 * saving an edit that did not touch the phone field at all - the record was
 * colliding with itself.
 *
 * The uniqueness rule is scoped two ways and both matter: `ignore()` so a record
 * does not block its own update, and a `business_id` clause so two shops may
 * each hold the same customer phone. A regression in either one is invisible
 * until someone tries to correct a name.
 */
class PartyPhoneUniquenessTest extends TestCase
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

    private function party(array $attributes = []): Party
    {
        return Party::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Existing Party',
            'phone' => '9000000001',
            'type' => 'Customer',
            'opening_balance_type' => 'credit',
        ], $attributes));
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Existing Customer',
            'phone' => '9100000001',
            'type' => 'Customer',
            'opening_balance_type' => 'credit',
        ], $attributes));
    }

    // ── parties ──────────────────────────────────────────────────────────────

    public function test_a_party_may_keep_its_own_phone_number(): void
    {
        $party = $this->party();

        $this->actingAs($this->user)
            ->putJson("/api/v1/parties/{$party->id}", [
                'name' => 'Renamed Party',
                'type' => 'Customer',
                'phone' => $party->phone,
            ])
            ->assertSuccessful();

        $this->assertSame('Renamed Party', $party->fresh()->name);
    }

    public function test_a_party_may_not_take_another_partys_phone(): void
    {
        $this->party(['phone' => '9000000002', 'name' => 'Someone Else']);
        $party = $this->party();

        $this->actingAs($this->user)
            ->putJson("/api/v1/parties/{$party->id}", [
                'name' => 'Existing Party',
                'type' => 'Customer',
                'phone' => '9000000002',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_party_may_move_to_an_unused_phone(): void
    {
        $party = $this->party();

        $this->actingAs($this->user)
            ->putJson("/api/v1/parties/{$party->id}", [
                'name' => 'Existing Party',
                'type' => 'Customer',
                'phone' => '9000000999',
            ])
            ->assertSuccessful();

        $this->assertSame('9000000999', $party->fresh()->phone);
    }

    /**
     * The number is only taken within the shop that owns it.
     */
    public function test_another_business_may_hold_the_same_phone(): void
    {
        $otherBusiness = Business::factory()->create();
        Party::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Shop Contact',
            'phone' => '9000000003',
            'type' => 'Customer',
            'opening_balance_type' => 'credit',
        ]);

        $party = $this->party();

        $this->actingAs($this->user)
            ->putJson("/api/v1/parties/{$party->id}", [
                'name' => 'Existing Party',
                'type' => 'Customer',
                'phone' => '9000000003',
            ])
            ->assertSuccessful();
    }

    // ── customers ────────────────────────────────────────────────────────────

    public function test_a_customer_may_keep_its_own_phone_number(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->user)
            ->putJson("/api/v1/customers/{$customer->id}", [
                'name' => 'Renamed Customer',
                'type' => 'Customer',
                'phone' => $customer->phone,
            ])
            ->assertSuccessful();

        $this->assertSame('Renamed Customer', $customer->fresh()->name);
    }

    public function test_a_customer_may_not_take_another_customers_phone(): void
    {
        $this->customer(['phone' => '9100000002', 'name' => 'Someone Else']);
        $customer = $this->customer();

        $this->actingAs($this->user)
            ->putJson("/api/v1/customers/{$customer->id}", [
                'name' => 'Existing Customer',
                'type' => 'Customer',
                'phone' => '9100000002',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    /**
     * The app sends its edits as POST with `_method=put`, which is the path a
     * shopkeeper actually exercises; it must land on the update rules.
     */
    public function test_a_method_spoofed_update_keeps_its_own_phone(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->user)
            ->post("/api/v1/customers/{$customer->id}", [
                '_method' => 'put',
                'name' => 'Spoofed Rename',
                'type' => 'Customer',
                'phone' => $customer->phone,
            ])
            ->assertSuccessful();

        $this->assertSame('Spoofed Rename', $customer->fresh()->name);
    }
}

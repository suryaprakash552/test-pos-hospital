<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reported from the shop floor: add a customer (phone, name, relationship
 * "Mother") - saves fine. Add another with the same phone and the same name,
 * only changing the relationship - refused as "The phone has already been
 * taken."
 *
 * `relationship` exists specifically so one phone can cover a household of
 * separate billing identities (mother, father, child, ...); a phone that is
 * only ever taken relative to the *same* relationship, per PartyDuplicateRuleTest,
 * was never told about that field at all, so it collided on the phone alone.
 */
class PartyRelationshipSharesPhoneTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($this->owner);
    }

    public function test_a_customer_may_share_a_phone_under_a_different_relationship(): void
    {
        $this->postJson('/api/v1/customers', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Mother',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $this->postJson('/api/v1/customers', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Self',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $this->assertSame(2, Customer::where('business_id', $this->business->id)
            ->where('phone', '9347732540')
            ->count());
    }

    public function test_a_party_may_share_a_phone_under_a_different_relationship(): void
    {
        $this->postJson('/api/v1/parties', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Mother',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $this->postJson('/api/v1/parties', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Self',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $this->assertSame(2, Party::where('business_id', $this->business->id)
            ->where('phone', '9347732540')
            ->count());
    }

    public function test_the_same_relationship_on_the_same_phone_is_still_refused(): void
    {
        // Two customers, one phone, both explicitly "Mother" - that is the
        // duplicate the rule exists to catch, not a household member.
        $this->postJson('/api/v1/customers', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Mother',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $this->postJson('/api/v1/customers', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Mother',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_two_customers_with_no_relationship_still_collide_on_phone(): void
    {
        // No relationship set on either side is its own bucket ("Self"/blank),
        // so this must behave exactly like the pre-existing phone-only rule.
        $this->postJson('/api/v1/customers', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $this->postJson('/api/v1/customers', [
            'name' => 'Someone Else',
            'phone' => '9347732540',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_editing_a_relationship_to_match_a_sibling_record_is_refused(): void
    {
        $this->postJson('/api/v1/customers', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Mother',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $this->postJson('/api/v1/customers', [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Self',
            'type' => 'Customer',
            'opening_balance_type' => 'cash',
        ])->assertSuccessful();

        $self = Customer::where('business_id', $this->business->id)
            ->where('relationship', 'Self')
            ->firstOrFail();

        $this->putJson("/api/v1/customers/{$self->id}", [
            'name' => 'Rajeswari',
            'phone' => '9347732540',
            'relationship' => 'Mother',
            'type' => 'Customer',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }
}

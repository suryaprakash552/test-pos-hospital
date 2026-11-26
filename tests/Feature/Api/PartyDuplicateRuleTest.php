<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What makes two contacts the same contact.
 *
 * The Add Customer form blocked on `phoneMatch || nameMatch`, so a second
 * "Rahul Kumar" could not be created at all. Two customers sharing a name is
 * ordinary — it is not a mistake, and a till that refuses it is wrong.
 *
 * The phone number is the constraint that actually identifies a person, and it
 * is the one the server enforces. This asserts that the client-side relaxation
 * did not come with any weakening of the server rule: the name is free, the
 * phone is not, and both are scoped to the signed-in business.
 */
class PartyDuplicateRuleTest extends TestCase
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
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($this->owner);
    }

    /** @param array<string, mixed> $overrides */
    private function createParty(array $overrides = [])
    {
        return $this->postJson('/api/v1/parties', array_merge([
            'name'                 => 'Rahul Kumar',
            'phone'                => '9000000001',
            'type'                 => 'Customer',
            // Required by StorePartyRequest; unrelated to the duplicate rule
            // under test, but omitting it would refuse every create for the
            // wrong reason.
            'opening_balance_type' => 'cash',
        ], $overrides));
    }

    public function test_the_same_name_with_a_different_phone_is_allowed(): void
    {
        // The decision. Two people called Rahul Kumar are two people.
        $this->createParty()->assertSuccessful();
        $this->createParty(['phone' => '9000000002'])->assertSuccessful();

        $this->assertSame(2, Party::where('business_id', $this->business->id)
            ->where('name', 'Rahul Kumar')
            ->count());
    }

    public function test_the_same_phone_with_a_different_name_is_refused(): void
    {
        // Unchanged, and must stay that way: the phone is the identity.
        $this->createParty()->assertSuccessful();

        $this->createParty(['name' => 'Someone Else'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_contact_keeps_its_own_phone_when_edited(): void
    {
        $this->createParty()->assertSuccessful();

        $party = Party::where('business_id', $this->business->id)->firstOrFail();

        // Editing without changing the number must not collide with itself.
        $this->putJson("/api/v1/parties/{$party->id}", [
            'name'  => 'Rahul Kumar Sharma',
            'phone' => '9000000001',
            'type'  => 'Customer',
        ])->assertSuccessful();

        $this->assertDatabaseHas('parties', [
            'id'   => $party->id,
            'name' => 'Rahul Kumar Sharma',
        ]);
    }

    public function test_another_business_may_hold_the_same_phone(): void
    {
        // Tenant isolation: uniqueness is per business, not global. Two shops
        // serving the same customer is normal.
        $this->createParty()->assertSuccessful();

        $otherBusiness = Business::factory()->create();
        $otherOwner = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($otherOwner)
            ->postJson('/api/v1/parties', [
                'name'                 => 'Rahul Kumar',
                'phone'                => '9000000001',
                'type'                 => 'Customer',
                'opening_balance_type' => 'cash',
            ])
            ->assertSuccessful();
    }

    public function test_several_contacts_may_have_no_phone_at_all(): void
    {
        // The second defect in the same block: the client compared '' against
        // every contact saved without a number, so the second phone-less
        // customer was refused as a duplicate of the first. The server has
        // never refused it.
        $this->createParty(['phone' => null, 'name' => 'Walk-in'])
            ->assertSuccessful();
        $this->createParty(['phone' => null, 'name' => 'Walk-in Two'])
            ->assertSuccessful();

        $this->assertSame(2, Party::where('business_id', $this->business->id)
            ->whereNull('phone')
            ->count());
    }

    public function test_a_missing_name_is_still_refused(): void
    {
        // Relaxing the duplicate rule must not relax the required fields.
        $this->createParty(['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_an_unknown_type_is_still_refused(): void
    {
        $this->createParty(['type' => 'Wizard'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }
}

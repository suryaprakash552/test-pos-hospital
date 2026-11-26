<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a contact the way the app actually does it.
 *
 * Reported from the shop floor: saving a customer edit shows "something went
 * wrong". The existing party tests send a JSON `PUT`; the app does not. It
 * builds a **multipart POST** carrying `_method=put`, and — this is the part
 * that matters — its `addField` helper *omits any field whose value is null or
 * empty*:
 *
 * ```dart
 * void addField(String key, String? value) {
 *   if (value != null && value.isNotEmpty) request.fields[key] = value;
 * }
 * ```
 *
 * So a contact with no email, no address and no relationship sends a much
 * smaller request than the tests were exercising, and any rule that assumes a
 * field is present fails only against the real client.
 */
class PartyEditFromAppTest extends TestCase
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
            'name' => 'Rahul Sharma',
            'type' => 'Customer',
            'phone' => '9701842105',
            'opening_balance' => 0,
            'opening_balance_type' => 'cash',
        ], $attributes));
    }

    /**
     * The smallest request the app can send: a contact with nothing optional
     * filled in.
     */
    public function test_a_minimal_edit_succeeds(): void
    {
        $party = $this->party();

        $response = $this->actingAs($this->user)->post(
            "/api/v1/parties/{$party->id}",
            [
                '_method' => 'put',
                'name' => 'Rahul Sharma Updated',
                'type' => 'Customer',
                'phone' => '9701842105',
            ]
        );

        $response->assertOk();
        $this->assertSame('Rahul Sharma Updated', $party->fresh()->name);
    }

    public function test_editing_without_touching_the_phone_is_allowed(): void
    {
        $party = $this->party();

        $this->actingAs($this->user)->post(
            "/api/v1/parties/{$party->id}",
            [
                '_method' => 'put',
                'name' => 'Corrected Name',
                'type' => 'Customer',
                'phone' => '9701842105',
            ]
        )->assertOk();
    }

    /**
     * `addField` drops an empty phone entirely, so the request simply has no
     * `phone` key. The rule is `nullable`, so this must be accepted.
     */
    public function test_a_contact_with_no_phone_can_be_edited(): void
    {
        $party = $this->party(['phone' => null]);

        $this->actingAs($this->user)->post(
            "/api/v1/parties/{$party->id}",
            [
                '_method' => 'put',
                'name' => 'Walk-in Customer',
                'type' => 'Customer',
            ]
        )->assertOk();
    }

    /**
     * The balance must survive an edit that never mentions it — the edit screen
     * does not render the field, so `addField` omits it.
     */
    public function test_an_edit_that_omits_the_balance_leaves_it_alone(): void
    {
        $party = $this->party([
            'opening_balance' => 500,
            'opening_balance_type' => 'due',
        ]);

        $this->actingAs($this->user)->post(
            "/api/v1/parties/{$party->id}",
            [
                '_method' => 'put',
                'name' => 'Rahul Sharma',
                'type' => 'Customer',
                'phone' => '9701842105',
            ]
        )->assertOk();

        $fresh = $party->fresh();
        $this->assertEquals(500, $fresh->opening_balance);
        $this->assertSame('due', $fresh->opening_balance_type);
    }

    public function test_every_allowed_type_is_accepted(): void
    {
        foreach ([
            'Retailer', 'Dealer', 'Wholesaler',
            'Supplier', 'Manufacturer', 'Customer',
        ] as $type) {
            $party = $this->party(['phone' => null]);

            $this->actingAs($this->user)->post(
                "/api/v1/parties/{$party->id}",
                ['_method' => 'put', 'name' => 'X', 'type' => $type]
            )->assertOk();

            $this->assertSame($type, $party->fresh()->type);
        }
    }

    public function test_another_businesses_contact_is_not_reachable(): void
    {
        $otherBusiness = Business::factory()->create();
        $foreign = Party::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Their Customer',
            'type' => 'Customer',
            'opening_balance' => 0,
            'opening_balance_type' => 'cash',
        ]);

        $this->actingAs($this->user)->post(
            "/api/v1/parties/{$foreign->id}",
            ['_method' => 'put', 'name' => 'Hijacked', 'type' => 'Customer']
        )->assertNotFound();

        $this->assertSame('Their Customer', $foreign->fresh()->name);
    }
}

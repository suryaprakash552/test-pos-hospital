<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\LoyaltyPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating and listing loyalty packages (§2).
 *
 * Every other loyalty area had endpoint coverage — exclusions, subscriptions,
 * refunds, reports, reminders — but the package master itself, which all of them
 * depend on, had none. It was reported from the field as "the package is not
 * saved" followed by "could not load the loyalty packages", so this exercises
 * exactly what the app sends.
 */
class LoyaltyPackageEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    /** The exact body `LoyaltyPackage.toJson()` builds in the Flutter client. */
    private function appPayload(array $overrides = []): array
    {
        return array_merge([
            'code'                => 'GOLD',
            'name'                => 'Gold',
            'cost'                => 999,
            'validity_type'       => 'months',
            'validity_period'     => 12,
            'discount_type'       => 'percentage',
            'discount_value'      => 10,
            'max_discount_amount' => null,
            'discount_mode'       => 'automatic',
            'renewal_allowed'     => true,
            'upgrade_allowed'     => true,
            'priority'            => 1,
            'status'              => true,
        ], $overrides);
    }

    public function test_a_package_can_be_created(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload());

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Gold');

        $this->assertDatabaseHas('loyalty_packages', [
            'business_id' => $this->business->id,
            'code'        => 'GOLD',
        ]);
    }

    public function test_an_explicit_null_ceiling_is_accepted(): void
    {
        // The client sends `max_discount_amount` as an explicit null so that
        // clearing the field removes the ceiling rather than leaving the old
        // one. A rule that rejected null here would fail every create.
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload([
                'max_discount_amount' => null,
            ]));

        $response->assertOk();
        $this->assertNull($response->json('data.max_discount_amount'));
    }

    public function test_a_fixed_term_package_sends_period_one(): void
    {
        // For a fixed term the dates carry the meaning, but the server still
        // requires a positive period, so the client sends 1.
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload([
                'code'                 => 'SEASON',
                'name'                 => 'Season',
                'validity_type'        => 'days',
                'validity_period'      => 1,
                'effective_start_date' => now()->toDateString(),
                'effective_end_date'   => now()->addDays(30)->toDateString(),
            ]));

        $response->assertOk();
    }

    public function test_the_list_returns_what_was_created(): void
    {
        $this->actingAs($this->owner)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload())
            ->assertOk();

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/loyalty-packages');

        $response->assertOk();
        $response->assertJsonPath('data.0.code', 'GOLD');
    }

    public function test_the_list_is_scoped_to_the_signed_in_business(): void
    {
        $otherBusiness = Business::factory()->create();
        LoyaltyPackage::create($this->appPayload([
            'code'        => 'THEIRS',
            'name'        => 'Theirs',
            'business_id' => $otherBusiness->id,
        ]));

        $response = $this->actingAs($this->owner)
            ->getJson('/api/v1/loyalty-packages');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_a_duplicate_code_is_refused_with_422(): void
    {
        // 422 matters to the client: it renders the field errors inline rather
        // than the generic "could not save" message.
        $this->actingAs($this->owner)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload())
            ->assertOk();

        $this->actingAs($this->owner)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload(['name' => 'Gold II']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_a_package_can_be_updated(): void
    {
        $created = $this->actingAs($this->owner)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload())
            ->json('data.id');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/loyalty-packages/{$created}", $this->appPayload([
                'name' => 'Gold Plus',
            ]))
            ->assertOk();

        $this->assertDatabaseHas('loyalty_packages', [
            'id'   => $created,
            'name' => 'Gold Plus',
        ]);
    }

    public function test_staff_can_view_packages_but_cannot_manage_them(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'staff',
            'visibility'  => [],
        ]);

        $this->actingAs($staff)
            ->getJson('/api/v1/loyalty-packages')
            ->assertOk();

        $this->actingAs($staff)
            ->postJson('/api/v1/loyalty-packages', $this->appPayload())
            ->assertForbidden();
    }
}

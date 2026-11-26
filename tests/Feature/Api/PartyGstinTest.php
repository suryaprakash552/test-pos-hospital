<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Party;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The other party's GST registration, on both sides of the ledger.
 *
 * A tax invoice issued to a registered buyer has to carry that buyer's GSTIN
 * (Rule 46(e)) or they cannot claim input credit against it; input credit on a
 * purchase is claimed against the supplier's. Neither `customers` nor `parties`
 * had the column, so every bill this shop wrote was effectively B2C whatever the
 * buyer's registration status.
 *
 * Stored only when it is well-formed. A malformed GSTIN is worse than a blank
 * one: the bill is accepted at the counter and the filing that carries it is
 * rejected months later, by which time the customer is long gone.
 */
class PartyGstinTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = '29AAACR5055K1Z5';

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

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Distributors',
            'type' => 'Supplier',
            'phone' => '9000000123',
            'opening_balance_type' => 'credit',
        ], $overrides);
    }

    public function test_a_supplier_gstin_is_stored(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/parties', $this->payload(['gstin' => self::VALID]))
            ->assertSuccessful();

        $this->assertSame(self::VALID, Party::first()->gstin);
    }

    public function test_a_customer_gstin_is_stored(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/customers', $this->payload([
                'name' => 'Registered Buyer',
                'type' => 'Customer',
                'gstin' => self::VALID,
            ]))
            ->assertSuccessful();

        $this->assertSame(self::VALID, Customer::first()->gstin);
    }

    /**
     * The ordinary case: a walk-in has no registration at all, and that is a
     * fact about them rather than a missing field.
     */
    public function test_a_party_without_a_gstin_saves_normally(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/parties', $this->payload())
            ->assertSuccessful();

        $this->assertNull(Party::first()->gstin);
    }

    /** @return array<string, array{string}> */
    public static function provideMalformedGstins(): array
    {
        return [
            'too short'          => ['29AAACR5055K1Z'],
            'too long'           => ['29AAACR5055K1Z55'],
            'letters for state'  => ['AAAAACR5055K1Z5'],
            'missing the Z'      => ['29AAACR5055K1X5'],
            'plainly not one'    => ['NOT A GSTIN!!!!'],
        ];
    }

    /** @dataProvider provideMalformedGstins */
    public function test_a_malformed_gstin_is_refused(string $gstin): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/parties', $this->payload(['gstin' => $gstin]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('gstin');

        $this->assertSame(0, Party::count());
    }

    /**
     * A GSTIN is case-insensitive, so this is the same registration typed in
     * lower case — normalised rather than refused, so the invoice prints it the
     * way the department writes it.
     */
    public function test_a_lowercase_gstin_is_accepted_and_stored_upper_case(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/parties', $this->payload([
                'gstin' => strtolower(self::VALID),
            ]))
            ->assertSuccessful();

        $this->assertSame(self::VALID, Party::first()->gstin);
    }

    public function test_a_malformed_gstin_is_refused_on_update_too(): void
    {
        $party = Party::create([
            'business_id' => $this->business->id,
            'name' => 'Acme Distributors',
            'phone' => '9000000123',
            'type' => 'Supplier',
            'gstin' => self::VALID,
            'opening_balance_type' => 'credit',
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/parties/{$party->id}", $this->payload([
                'gstin' => 'RUBBISH',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('gstin');

        $this->assertSame(self::VALID, $party->fresh()->gstin,
            'a rejected update must not disturb the number already stored');
    }
}

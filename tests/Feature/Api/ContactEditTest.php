<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing an existing contact.
 *
 * `opening_balance_type` was `required` on update, but the edit screen does not
 * render it - so saving any change at all came back with "The opening balance type
 * field is required."
 *
 * Making it optional alone would have been worse than the bug: `adjustedPayload`
 * reads a missing opening balance as 0, so an edit that never mentioned the field
 * would have zeroed the stored balance and moved the contact's due by the old
 * amount. Correcting a phone number would have quietly changed what they owe.
 */
class ContactEditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        $this->customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Original Name',
            'phone' => '5550001',
            'type' => 'Customer',
            'opening_balance' => 500,
            'opening_balance_type' => 'due',
            'due' => 500,
            'credit_limit' => 2000,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function edit(array $payload)
    {
        return $this->actingAs($this->user)
            ->putJson("/api/v1/customers/{$this->customer->id}", $payload + [
                'name' => 'Original Name',
                'phone' => '5550001',
                // Sent by the edit screen; required independently of this fix.
                'type' => 'Customer',
            ]);
    }

    public function test_an_edit_without_the_opening_balance_type_is_accepted(): void
    {
        $this->edit(['name' => 'Corrected Name'])->assertSuccessful();

        $this->assertSame('Corrected Name', $this->customer->fresh()->name);
    }

    public function test_an_ordinary_edit_leaves_the_opening_balance_alone(): void
    {
        $this->edit(['phone' => '5559999'])->assertSuccessful();

        $customer = $this->customer->fresh();
        $this->assertSame(500.0, (float) $customer->opening_balance);
        $this->assertSame('due', $customer->opening_balance_type);
    }

    public function test_an_ordinary_edit_does_not_move_what_the_customer_owes(): void
    {
        $this->edit(['phone' => '5559999'])->assertSuccessful();

        $this->assertSame(
            500.0,
            (float) $this->customer->fresh()->due,
            'Correcting a phone number must not change the balance.',
        );
    }

    public function test_an_ordinary_edit_leaves_the_credit_limit_alone(): void
    {
        $this->edit(['name' => 'Corrected Name'])->assertSuccessful();

        $this->assertSame(2000.0, (float) $this->customer->fresh()->credit_limit);
    }

    public function test_the_opening_balance_can_still_be_changed_deliberately(): void
    {
        $this->edit([
            'opening_balance' => 800,
            'opening_balance_type' => 'due',
        ])->assertSuccessful();

        $customer = $this->customer->fresh();
        $this->assertSame(800.0, (float) $customer->opening_balance);
        // 500 due, opening raised by 300.
        $this->assertSame(800.0, (float) $customer->due);
    }

    public function test_the_opening_balance_can_be_set_to_zero_deliberately(): void
    {
        $this->edit([
            'opening_balance' => 0,
            'opening_balance_type' => 'due',
        ])->assertSuccessful();

        $customer = $this->customer->fresh();
        $this->assertSame(0.0, (float) $customer->opening_balance);
        $this->assertSame(0.0, (float) $customer->due);
    }

    public function test_an_unknown_balance_type_is_still_refused(): void
    {
        $this->edit(['opening_balance_type' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('opening_balance_type');
    }
}

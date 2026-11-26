<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\CounterSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cash drawer float.
 *
 * `amount` and `upi_amount` were `nullable|numeric` with no floor, so a negative
 * float could be recorded - which silently corrupts the reconciliation the table
 * exists to support. `type` was any string at all.
 */
class CounterSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function openDrawer(array $payload)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/counter', $payload);
    }

    public function test_a_float_can_be_recorded(): void
    {
        $this->openDrawer(['amount' => 500, 'upi_amount' => 0])->assertSuccessful();

        $this->assertSame(500.0, (float) CounterSession::first()->amount);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function provideRejectedPayloads(): array
    {
        return [
            'negative cash' => [['amount' => -500], 'amount'],
            'negative upi' => [['upi_amount' => -250], 'upi_amount'],
            'unknown type' => [['amount' => 100, 'type' => 'whatever'], 'type'],
        ];
    }

    /** @dataProvider provideRejectedPayloads */
    public function test_an_impossible_drawer_entry_is_refused(array $payload, string $field): void
    {
        $this->openDrawer($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, CounterSession::count());
    }

    public function test_the_known_types_are_accepted(): void
    {
        foreach (['counter', 'opening', 'closing'] as $type) {
            $this->openDrawer(['amount' => 100, 'type' => $type])->assertSuccessful();
        }

        $this->assertSame(3, CounterSession::count());
    }

    public function test_a_drawer_entry_belongs_to_the_user_who_made_it(): void
    {
        $this->openDrawer(['amount' => 100])->assertSuccessful();

        // The table carries no business_id; `user_id` is what scopes it, and a
        // user belongs to exactly one business.
        $this->assertSame($this->user->id, CounterSession::first()->user_id);
    }

    /**
     * A shop-owner has no StaffLoginSession - `LoginAction` creates one only for
     * `role === staff`. Both reads below used that row as the working period and
     * gave up without it, so the owner's float saved and then vanished.
     */
    public function test_a_saved_float_is_visible_to_a_shop_owner_without_a_login_session(): void
    {
        $this->openDrawer(['amount' => 500, 'upi_amount' => 250])->assertSuccessful();

        $this->actingAs($this->user)
            ->getJson('/api/v1/counter')
            ->assertSuccessful()
            // Decimal columns serialise as strings ('500.00'); the app parses
            // them, so the value is what matters rather than the JSON type.
            ->assertJsonPath('cash_amount', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('upi_amount', fn ($v) => (float) $v === 250.0)
            // The app reads the figures out of `data`; the early return used to
            // omit the key entirely.
            ->assertJsonPath('data.amount', fn ($v) => (float) $v === 500.0);
    }

    public function test_the_view_always_carries_a_data_key(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/counter')
            ->assertSuccessful()
            ->assertJsonPath('data', null)
            ->assertJsonPath('cash_amount', 0);
    }

    public function test_a_shop_owner_can_update_the_counter(): void
    {
        $this->openDrawer(['amount' => 500])->assertSuccessful();

        $this->actingAs($this->user)
            ->putJson('/api/v1/counter', ['amount' => 750, 'upi_amount' => 100])
            ->assertSuccessful();

        $counter = CounterSession::first();
        $this->assertSame(750.0, (float) $counter->amount);
        $this->assertSame(100.0, (float) $counter->upi_amount);
        $this->assertSame(1, CounterSession::count(), 'Updating must not add a row.');
    }

    public function test_updating_with_no_existing_counter_creates_one(): void
    {
        $this->actingAs($this->user)
            ->putJson('/api/v1/counter', ['amount' => 300])
            ->assertSuccessful();

        $this->assertSame(300.0, (float) CounterSession::first()->amount);
    }

    public function test_an_update_is_refused_a_negative_float(): void
    {
        $this->openDrawer(['amount' => 500])->assertSuccessful();

        $this->actingAs($this->user)
            ->putJson('/api/v1/counter', ['amount' => -50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertSame(500.0, (float) CounterSession::first()->amount);
    }

    public function test_yesterdays_float_does_not_carry_into_today(): void
    {
        $this->openDrawer(['amount' => 500])->assertSuccessful();

        // The owner's working period is the calendar day, so a drawer opened
        // before today must not be presented as today's float.
        CounterSession::first()->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($this->user)
            ->getJson('/api/v1/counter')
            ->assertSuccessful()
            ->assertJsonPath('cash_amount', 0)
            ->assertJsonPath('data', null);
    }
    // ── The float, once a shift has been closed ─────────────────────────────

    /**
     * An edited float must stay edited.
     *
     * Reported as "editing the amount is not updating the value correctly, it
     * falls back to the previous amount", and intermittently so. Two causes, both
     * in what "the current counter" meant: closing a counter writes a newer row
     * holding what was *counted* at handover, and it was being read back as the
     * float; and rows written in the same second tie on `created_at`, so which
     * one came back was left to the database.
     */
    public function test_closing_the_counter_does_not_become_the_float(): void
    {
        $this->openDrawer(['amount' => 300, 'upi_amount' => 400])->assertSuccessful();

        // End of shift: what was actually counted in the drawer.
        $this->openDrawer([
            'amount' => 1250,
            'upi_amount' => 900,
            'type' => 'closing',
        ])->assertSuccessful();

        $response = $this->actingAs($this->user)->getJson('/api/v1/counter')
            ->assertSuccessful();

        $this->assertEquals(300, $response->json('data.amount'));
        $this->assertEquals(400, $response->json('data.upi_amount'));
    }

    public function test_an_edit_after_closing_updates_the_float_not_the_handover(): void
    {
        $this->openDrawer(['amount' => 300, 'upi_amount' => 400])->assertSuccessful();
        $this->openDrawer([
            'amount' => 1250,
            'upi_amount' => 900,
            'type' => 'closing',
        ])->assertSuccessful();

        $this->actingAs($this->user)
            ->putJson('/api/v1/counter', ['amount' => 500, 'upi_amount' => 600])
            ->assertSuccessful();

        $float = CounterSession::where('type', 'counter')->latest('id')->first();
        $closing = CounterSession::where('type', 'closing')->latest('id')->first();

        $this->assertEquals(500, $float->amount);
        $this->assertEquals(600, $float->upi_amount);

        // The handover record is evidence of what was counted. An edit to the
        // float must not rewrite it.
        $this->assertEquals(1250, $closing->amount);
        $this->assertEquals(900, $closing->upi_amount);
    }

    /** Same second, two rows: the answer must not depend on the database's mood. */
    public function test_the_newest_float_wins_even_when_the_timestamps_tie(): void
    {
        $stamp = now();

        $older = CounterSession::create([
            'user_id' => $this->user->id,
            'amount' => 100,
            'upi_amount' => 100,
            'type' => 'counter',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $newer = CounterSession::create([
            'user_id' => $this->user->id,
            'amount' => 750,
            'upi_amount' => 250,
            'type' => 'counter',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $this->assertGreaterThan($older->id, $newer->id);

        $response = $this->actingAs($this->user)->getJson('/api/v1/counter')
            ->assertSuccessful();

        $this->assertEquals(750, $response->json('data.amount'));
    }

    /** The ordinary case still works: an edit is read straight back. */
    public function test_an_edited_float_reads_back_as_edited(): void
    {
        $this->openDrawer(['amount' => 300, 'upi_amount' => 400])->assertSuccessful();

        $this->actingAs($this->user)
            ->putJson('/api/v1/counter', ['amount' => 850, 'upi_amount' => 150])
            ->assertSuccessful();

        $response = $this->actingAs($this->user)->getJson('/api/v1/counter');

        $this->assertEquals(850, $response->json('data.amount'));
        $this->assertEquals(150, $response->json('data.upi_amount'));
    }
}

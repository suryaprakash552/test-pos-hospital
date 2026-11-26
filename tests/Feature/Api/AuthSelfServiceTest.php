<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\StaffLoginSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The account endpoints a user reaches without help: signing out, changing a
 * password, asking what the OTP settings are.
 *
 * Ten of these had no behavioural test at all — only `ApiHardeningTest`, which
 * asserts configuration rather than what the endpoints do. They are the ones
 * where a regression is least visible and most costly: nobody notices that
 * changing a password stopped checking the old one until an account is taken.
 */
class AuthSelfServiceTest extends TestCase
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
            'password' => Hash::make('original-password'),
        ]);
    }

    // ── change-password ────────────────────────────────────────────────────

    public function test_a_password_can_be_changed_with_the_current_one(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/change-password', [
                'current_password' => 'original-password',
                'password' => 'a-new-password',
            ])
            ->assertOk();

        $this->assertTrue(
            Hash::check('a-new-password', $this->user->fresh()->password),
        );
    }

    /** The whole point of asking for the current password. */
    public function test_a_wrong_current_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/change-password', [
                'current_password' => 'not-the-password',
                'password' => 'a-new-password',
            ])
            ->assertStatus(422);

        $this->assertTrue(
            Hash::check('original-password', $this->user->fresh()->password),
            'the password must be unchanged after a failed attempt',
        );
    }

    public function test_reusing_the_same_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/change-password', [
                'current_password' => 'original-password',
                'password' => 'original-password',
            ])
            ->assertStatus(422);
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/change-password', [
                'current_password' => 'original-password',
                'password' => 'abc',
            ])
            ->assertStatus(422);

        $this->assertTrue(
            Hash::check('original-password', $this->user->fresh()->password),
        );
    }

    public function test_changing_a_password_requires_a_session(): void
    {
        $this->postJson('/api/v1/change-password', [
            'current_password' => 'original-password',
            'password' => 'a-new-password',
        ])->assertStatus(401);
    }

    // ── sign-out ───────────────────────────────────────────────────────────

    public function test_signing_out_revokes_the_token_that_made_the_call(): void
    {
        $token = $this->user->createToken('test-device')->plainTextToken;

        $this->assertSame(1, $this->user->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sign-out')
            ->assertOk();

        // The token row is gone, so it cannot authenticate again.
        $this->assertSame(
            0,
            $this->user->tokens()->count(),
            'signing out must revoke the token that made the call',
        );
    }

    public function test_signing_out_without_a_session_is_unauthorised(): void
    {
        $this->getJson('/api/v1/sign-out')->assertStatus(401);
    }

    /** Signing out closes a staff member's open shift, so the report can end it. */
    public function test_signing_out_closes_an_open_staff_session(): void
    {
        $staff = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);

        $session = StaffLoginSession::create([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'login_at' => now()->subHour(),
            'logout_at' => null,
        ]);

        $token = $staff->createToken('till')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sign-out')
            ->assertOk();

        $this->assertNotNull(
            $session->fresh()->logout_at,
            'an open shift must be closed when the staff member signs out',
        );
    }

    // ── otp-settings ───────────────────────────────────────────────────────

    public function test_otp_settings_are_readable(): void
    {
        $this->getJson('/api/v1/otp-settings')->assertOk();
    }

    // ── sign-up ────────────────────────────────────────────────────────────

    public function test_signing_up_with_an_existing_email_is_refused(): void
    {
        $this->postJson('/api/v1/sign-up', [
            'name' => 'Someone Else',
            'email' => $this->user->email,
            'password' => 'a-password',
            'password_confirmation' => 'a-password',
        ])->assertStatus(422);
    }

    public function test_signing_up_without_an_email_is_refused(): void
    {
        $this->postJson('/api/v1/sign-up', [
            'name' => 'No Email',
            'password' => 'a-password',
        ])->assertStatus(422);
    }
}

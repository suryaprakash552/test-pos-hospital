<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class DeviceSessionLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_failed_logins_lock_that_email_and_ip_for_three_hours(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'shop-owner',
            'email' => 'locked-login@example.test',
            'password' => bcrypt('password'),
        ]);

        foreach (range(1, 4) as $_) {
            $this->postJson('/api/v1/sign-in', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertNotFound();
        }

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertTooManyRequests()
            ->assertJsonPath('reason', 'login_locked')
            ->assertJsonPath('message', 'Too many unsuccessful login attempts. Try again after three hours.');

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertTooManyRequests();

        $this->travel(3)->hours();
        $this->travel(1)->second();

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'after-lock',
            'device_name' => 'Front counter',
        ])->assertOk();
    }

    public function test_five_devices_are_allowed_and_a_sixth_is_blocked(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'shop-owner',
            'email' => 'devices@example.test',
            'password' => bcrypt('password'),
        ]);

        foreach ([1, 2, 3, 4, 5] as $device) {
            $this->postJson('/api/v1/sign-in', [
                'email' => $user->email,
                'password' => 'password',
                'device_id' => "device-{$device}",
                'device_name' => "Till {$device}",
            ])->assertOk();
        }

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'device-6',
            'device_name' => 'Till 6',
        ])->assertConflict()
            ->assertJsonPath('message', 'This account is already active on five devices. Ask an administrator to sign out an unused device and try again.');

        $this->assertCount(5, $user->fresh()->tokens);
    }

    public function test_same_device_replaces_its_token_without_using_another_slot(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'shop-owner',
            'email' => 'same-device@example.test',
            'password' => bcrypt('password'),
        ]);

        $payload = [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'stable-installation-id',
            'device_name' => 'Front counter',
        ];

        $this->postJson('/api/v1/sign-in', $payload)->assertOk();
        $this->postJson('/api/v1/sign-in', $payload)->assertOk();

        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_user_can_list_and_force_logout_only_their_own_devices(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
        $other = User::factory()->create(['business_id' => $business->id]);
        $current = $user->createToken('api-token')->accessToken;
        $remote = $user->createToken('api-token')->accessToken;
        $foreign = $other->createToken('api-token')->accessToken;
        $remote->forceFill(['device_id' => 'remote', 'device_name' => 'Warehouse'])->save();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->assertJsonFragment(['device_name' => "Legacy device #{$current->id}"])
            ->assertJsonFragment(['device_name' => 'Warehouse']);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/auth/sessions/{$remote->id}")
            ->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $remote->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/auth/sessions/{$foreign->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->id]);
    }

    public function test_staff_cannot_force_logout_a_device(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'staff',
        ]);
        $remote = $staff->createToken('api-token')->accessToken;

        $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/v1/auth/sessions/{$remote->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only an administrator can force logout a device.');

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $remote->id]);
    }

    public function test_staff_credentials_cannot_release_a_device_before_login(): void
    {
        $business = Business::factory()->create();
        $staff = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'staff',
            'email' => 'staff-device@example.test',
            'password' => bcrypt('password'),
        ]);
        $remote = $staff->createToken('api-token')->accessToken;

        $this->postJson('/api/v1/sign-in/devices/release', [
            'email' => $staff->email,
            'password' => 'password',
            'token_id' => $remote->id,
        ])->assertForbidden()
            ->assertJsonPath('message', 'Only an administrator can force logout a device.');

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $remote->id]);
    }

    public function test_refresh_preserves_the_current_device_identity(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create(['business_id' => $business->id]);
        $issued = $user->createToken('api-token');
        $token = $issued->accessToken;
        $token->forceFill([
            'device_id' => 'front-counter-installation',
            'device_name' => 'Front Counter Tablet',
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$issued->plainTextToken)
            ->getJson('/api/v1/refresh-token')
            ->assertOk()
            ->assertJsonStructure(['token']);

        $this->assertCount(1, $user->fresh()->tokens);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'device_id' => 'front-counter-installation',
            'device_name' => 'Front Counter Tablet',
        ]);
    }
    // ── Getting out of a full account ───────────────────────────────────────

    private function userWithFiveDevices(string $email): User
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'shop-owner',
            'email' => $email,
            'password' => bcrypt('password'),
        ]);

        foreach ([1, 2, 3, 4, 5] as $device) {
            $token = $user->createToken('api-token')->accessToken;
            $token->forceFill([
                'device_id' => "device-{$device}",
                'device_name' => "Till {$device}",
            ])->save();
        }

        return $user->fresh();
    }

    /**
     * The refusal used to be a dead end.
     *
     * "Sign out one device and try again" was shown on the one screen where the
     * person is, by definition, not signed in anywhere they could go and do
     * that. These two endpoints are the way out, and they are reachable without
     * a token for exactly that reason.
     */
    public function test_the_signed_in_devices_can_be_listed_with_the_password(): void
    {
        $user = $this->userWithFiveDevices('list-devices@example.test');

        $response = $this->postJson('/api/v1/sign-in/devices', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(
            ['Till 1', 'Till 2', 'Till 3', 'Till 4', 'Till 5'],
            collect($response->json('data'))->pluck('device_name')->sort()->values()->all(),
        );
    }

    public function test_releasing_a_device_frees_a_slot_to_sign_in(): void
    {
        $user = $this->userWithFiveDevices('release-device@example.test');

        $tokenId = $this->postJson('/api/v1/sign-in/devices', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.0.id');

        $this->postJson('/api/v1/sign-in/devices/release', [
            'email' => $user->email,
            'password' => 'password',
            'token_id' => $tokenId,
        ])->assertOk();

        $this->assertCount(4, $user->fresh()->tokens);

        // The whole point: the sixth device can now sign in.
        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'device-6',
            'device_name' => 'Till 6',
        ])->assertOk();
    }

    /**
     * Reachable without a token is not the same as unauthenticated.
     *
     * Without this, the endpoint would be a way to enumerate accounts and sign
     * strangers' tills out.
     */
    public function test_a_wrong_password_learns_nothing_and_releases_nothing(): void
    {
        $user = $this->userWithFiveDevices('wrong-password@example.test');
        $tokenId = $user->tokens()->first()->id;

        $this->postJson('/api/v1/sign-in/devices', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(404)
            ->assertJsonMissingPath('data');

        $this->postJson('/api/v1/sign-in/devices/release', [
            'email' => $user->email,
            'password' => 'not-the-password',
            'token_id' => $tokenId,
        ])->assertStatus(404);

        $this->assertCount(5, $user->fresh()->tokens);
    }

    /** A token id from another account is simply not found. */
    public function test_it_cannot_release_another_accounts_device(): void
    {
        $mine = $this->userWithFiveDevices('mine@example.test');
        $theirs = $this->userWithFiveDevices('theirs@example.test');

        $theirToken = $theirs->tokens()->first();

        $this->postJson('/api/v1/sign-in/devices/release', [
            'email' => $mine->email,
            'password' => 'password',
            'token_id' => $theirToken->id,
        ])->assertStatus(404);

        $this->assertCount(5, $theirs->fresh()->tokens);
    }
    // ── The same handset, twice ─────────────────────────────────────────────

    /**
     * Reported from the shop: the device-limit message kept coming back, and
     * the device list showed the same phone twice — "vivo I2223" in two slots.
     *
     * The app's device id is generated once and kept in app storage, so
     * reinstalling it, clearing its data or restoring a backup hands the same
     * physical device a new id. It then took a second slot, and one phone plus
     * one tablet was enough to be refused as three devices.
     */
    public function test_the_same_handset_reclaims_its_slot_after_a_reinstall(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'shop-owner',
            'email' => 'reinstall@example.test',
            'password' => bcrypt('password'),
        ]);

        // The counter phone, then the tablet.
        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'install-1',
            'device_name' => 'vivo I2223',
        ])->assertOk();

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'tablet-1',
            'device_name' => 'samsung SM-X115',
        ])->assertOk();

        // The phone is reinstalled: same handset, new installation id.
        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'install-2',
            'device_name' => 'vivo I2223',
        ])->assertOk();

        $tokens = $user->fresh()->tokens;

        $this->assertCount(2, $tokens, 'one phone and one tablet is two devices');
        $this->assertSame(
            ['samsung SM-X115', 'vivo I2223'],
            $tokens->pluck('device_name')->sort()->values()->all(),
        );
    }

    /** And that still leaves room for a genuine third device. */
    public function test_a_reclaimed_slot_leaves_room_for_a_third_device(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'shop-owner',
            'email' => 'third@example.test',
            'password' => bcrypt('password'),
        ]);

        foreach ([['a', 'vivo I2223'], ['b', 'vivo I2223'], ['c', 'realme RMX3785']] as [$id, $name]) {
            $this->postJson('/api/v1/sign-in', [
                'email' => $user->email,
                'password' => 'password',
                'device_id' => $id,
                'device_name' => $name,
            ])->assertOk();
        }

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'd',
            'device_name' => 'samsung SM-X115',
        ])->assertOk();

        $this->assertCount(3, $user->fresh()->tokens);
    }

    /**
     * Legacy rows are not a device name.
     *
     * A login with no device details is recorded as "Legacy device"; treating
     * that as an identity would fold every such session into one.
     */
    public function test_legacy_sessions_are_not_folded_together(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'branch_id' => null,
            'role' => 'shop-owner',
            'email' => 'legacy@example.test',
            'password' => bcrypt('password'),
        ]);

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => 'явный-другой',
            'device_name' => 'Legacy device',
        ])->assertOk();

        $this->assertCount(2, $user->fresh()->tokens);
    }
}

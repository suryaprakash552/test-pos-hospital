<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Deployment defaults that are invisible until they matter.
 *
 * Each of these was found by probing a running server rather than by reading code,
 * and each fails silently: a limit that stops a till mid-sale, a session cookie
 * that travels over plain HTTP, a header naming the exact PHP version. Pinned here
 * because nothing else would notice them changing back.
 */
class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role'        => 'shop-owner',
        ]);
    }

    // ── Rate limit ───────────────────────────────────────────────────────────

    /**
     * The limit was 60/minute, which one till exceeds during ordinary trade —
     * opening the sale screen alone costs several requests.
     */
    public function test_the_api_allows_a_working_tills_traffic(): void
    {
        $this->assertGreaterThanOrEqual(
            300,
            config('pos.rate_limit.per_minute'),
            'a busy till must not be cut off mid-sale',
        );
    }

    public function test_the_limit_is_configurable(): void
    {
        config(['pos.rate_limit.per_minute' => 123]);

        $this->assertSame(123, config('pos.rate_limit.per_minute'));
    }

    /** The named limiter has to exist, or the routes referencing it 500. */
    public function test_the_named_limiter_is_registered(): void
    {
        $limiter = app(\Illuminate\Cache\RateLimiter::class)->limiter('pos-api');

        $this->assertNotNull($limiter, 'routes reference throttle:pos-api');
    }

    /**
     * The group used to apply its own 100/minute on top of the route's limiter, so
     * the lower of the two won and the configured value did nothing.
     */
    public function test_only_one_throttle_governs_the_api(): void
    {
        $kernel = app(\App\Http\Kernel::class);
        $groups = (fn () => $this->middlewareGroups)->call($kernel);

        $throttles = array_values(array_filter(
            $groups['api'] ?? [],
            fn ($m) => is_string($m) && str_contains($m, 'ThrottleRequests'),
        ));

        foreach ($throttles as $throttle) {
            $this->assertStringContainsString(
                'pos-api',
                $throttle,
                'a second, lower limit would silently override the configured one',
            );
        }
    }

    /**
     * Signing in is limited by failed *attempts*, not by request rate.
     *
     * This used to assert `throttle:5,1` on the route and had been failing ever
     * since the route moved to `throttle:30,1` — but it was checking the wrong
     * layer. The route throttle is a coarse cap on request volume, successful
     * logins included; the actual brute-force protection lives in
     * `AuthController::login`, which counts *failures* per email+IP, locks out
     * after five for three hours, and clears the counter on a success. A rate
     * limit of five requests a minute would lock out a legitimate user for
     * navigating, and would not stop a patient attacker at all.
     *
     * Asserted as behaviour, so it keeps holding if the limiter moves again.
     */
    public function test_repeated_failed_sign_ins_are_locked_out(): void
    {
        $business = \App\Models\Business::factory()->create();
        $user = \App\Models\User::factory()->create([
            'business_id' => $business->id,
            'email' => 'till@example.test',
            'password' => bcrypt('the-real-password'),
        ]);

        $wrong = ['email' => $user->email, 'password' => 'not-it'];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/sign-in', $wrong);
        }

        // The sixth is refused on the limiter, not on the credentials — and the
        // right password no longer gets through either.
        $this->postJson('/api/v1/sign-in', [
            'email' => $user->email,
            'password' => 'the-real-password',
        ])->assertStatus(429);
    }

    /** The general limit still applies to the route as an outer bound. */
    public function test_sign_in_still_carries_a_route_throttle(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/v1/sign-in',
        );

        $throttles = collect($route->gatherMiddleware())
            ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));

        $this->assertNotEmpty($throttles, 'sign-in must be rate limited');
    }

    // ── Session cookie ───────────────────────────────────────────────────────

    /**
     * Left unset, the cookie was sent over plain HTTP as readily as HTTPS — and an
     * unset variable is exactly what a deployment that never edits its env runs
     * with. Asserted against the shipped default and the rule behind it, not the
     * test environment, which deliberately runs over HTTP.
     */
    public function test_a_deployment_defaults_to_an_https_only_cookie(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SESSION_SECURE_COOKIE=true', $example);
        $this->assertStringContainsString('APP_ENV=production', $example);
    }

    /** And a deployment that deletes the line still gets it, from the default. */
    public function test_the_secure_default_follows_the_environment(): void
    {
        $config = file_get_contents(config_path('session.php'));

        $this->assertStringContainsString(
            "env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production')",
            $config,
            'an unset variable must still be secure in production',
        );
    }

    public function test_the_session_cookie_stays_out_of_javascript(): void
    {
        $this->assertTrue(config('session.http_only'));
        $this->assertSame('lax', config('session.same_site'));
    }

    // ── Headers ──────────────────────────────────────────────────────────────

    public function test_the_php_version_is_not_advertised(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/units');

        $this->assertNull(
            $response->headers->get('X-Powered-By'),
            'the exact PHP version tells an attacker which exploits to try',
        );
    }

    public function test_authenticated_data_is_never_cached_by_an_intermediary(): void
    {
        // A bearer token, as a real client sends: the no-store rule keys on that,
        // since a shop's stock and takings must not sit in a shared cache.
        $token = $this->user->createToken('probe')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/units')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}

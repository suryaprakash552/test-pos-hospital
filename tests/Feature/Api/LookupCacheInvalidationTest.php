<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A lookup created from the till has to appear in its own list.
 *
 * `rememberBusiness()` always writes *with* tags, and a tagged entry does not
 * live under the plain key: it is namespaced by `Cache::tags(...)` on a store
 * that supports them, and keyed by `versionedKey()` — the key plus a hash of
 * the tag stamps — on one that does not. `forgetBusiness()` deleted the plain
 * key, a third address that was never written to, so it did nothing.
 *
 * On this deployment (`CACHE_DRIVER=file`, no tag support) that meant a new
 * unit stayed missing from the units list for the full 24-hour TTL, while the
 * duplicate-name check could see it perfectly well — so the list said "no
 * matching units" for a name the form refused to create again.
 */
class LookupCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The driver the server actually runs, and the one the bug needed.
        Config::set('cache.default', 'array');

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
    }

    private function units(): array
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/units')
            ->assertSuccessful();

        return array_column($response->json('data'), 'unitName');
    }

    public function test_a_new_unit_appears_in_the_list_it_was_added_to(): void
    {
        // Warms the cache, which is what made the next read stale.
        $this->assertSame([], $this->units());

        $this->actingAs($this->user)
            ->postJson('/api/v1/units', ['unitName' => 'Box'])
            ->assertSuccessful();

        $this->assertContains('Box', $this->units(),
            'the unit was created but its own list could not see it');
    }

    public function test_a_renamed_unit_shows_its_new_name(): void
    {
        $unit = Unit::create([
            'unitName' => 'Bx',
            'business_id' => $this->user->business_id,
        ]);

        $this->assertContains('Bx', $this->units());

        $this->actingAs($this->user)
            ->putJson("/api/v1/units/{$unit->id}", ['unitName' => 'Box'])
            ->assertSuccessful();

        $names = $this->units();
        $this->assertContains('Box', $names);
        $this->assertNotContains('Bx', $names);
    }

    public function test_one_business_clearing_its_cache_does_not_expose_another(): void
    {
        $other = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        Unit::create([
            'unitName' => 'Crate',
            'business_id' => $other->business_id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/units', ['unitName' => 'Box'])
            ->assertSuccessful();

        $this->assertSame(['Box'], $this->units(),
            'clearing the tag must not pull another tenant\'s rows in');
    }
}

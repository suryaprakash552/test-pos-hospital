<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Every named route must own its name.
 *
 * This is not a style rule. `php artisan route:cache` refuses the *entire*
 * route table if any two routes share a name:
 *
 *   Unable to prepare route [businesses] for serialization.
 *   Another route has already been assigned name [business.store].
 *
 * There is no partial success and no warning — caching simply fails, and if
 * nobody reads the deploy output the app keeps running uncached, re-parsing
 * all 821 routes on every single request. That is exactly what was happening
 * in production, and it mattered: the reported symptom was checkouts timing
 * out at the 30-second client limit.
 *
 * Four collisions had accumulated, and each was invisible until caching was
 * attempted:
 *
 *   - `business.store` — the API resource and the web signup route
 *   - `term.index` — terms-conditions and, wrongly, data-deletion
 *   - `admin.interfaces.update` — a manual route re-declaring one
 *     `Route::resource` already made, under a doubled `admin/admin/` prefix.
 *     Registered last, it also won the name lookup, so the edit form had been
 *     posting to the doubled URL.
 *   - `api.` — two MultiBranch routes left unnamed inside a
 *     `->name('api.')` group, so both were named just the prefix
 *
 * That last one is the reason this test reports names rather than counts: an
 * unnamed route inside a named group does not look like a naming mistake at
 * the call site.
 */
class RouteNamesAreUniqueTest extends TestCase
{
    public function test_no_two_routes_share_a_name(): void
    {
        $seen = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! $name = $route->getName()) {
                continue;
            }

            $seen[$name][] = implode('|', $route->methods()).' '.$route->uri();
        }

        $duplicates = array_filter($seen, fn ($uris) => count($uris) > 1);

        $this->assertSame([], $duplicates, sprintf(
            "These route names are claimed more than once, which makes "
            ."`route:cache` fail for every route:\n%s",
            collect($duplicates)
                ->map(fn ($uris, $name) => "  {$name}\n      ".implode("\n      ", $uris))
                ->implode("\n")
        ));
    }

    /**
     * A route inheriting only its group's `->name()` prefix is the failure that
     * produced the `api.` collision, and it stays wrong even alone: `route()`
     * would have to be called with a trailing dot.
     */
    public function test_no_route_is_named_only_by_its_group_prefix(): void
    {
        $prefixOnly = [];

        foreach (app('router')->getRoutes() as $route) {
            if (($name = $route->getName()) && str_ends_with($name, '.')) {
                $prefixOnly[] = "{$name} <- ".$route->uri();
            }
        }

        $this->assertSame([], $prefixOnly,
            "These routes were left unnamed inside a `->name('...')` group, so "
            ."they carry the prefix alone:\n  ".implode("\n  ", $prefixOnly));
    }

    /**
     * The end the rules above serve. Guards against a collision arriving by a
     * route this test's own iteration somehow misses.
     */
    public function test_the_route_table_can_actually_be_cached(): void
    {
        $exit = Artisan::call('route:cache');

        // Leave no cache behind: a stale one would silently override later
        // route edits for anything else running against this checkout.
        Artisan::call('route:clear');

        $this->assertSame(0, $exit, 'route:cache failed: '.Artisan::output());
    }
}

<?php

namespace Tests\Feature;

use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Caching on a store that cannot tag.
 *
 * `CACHE_DRIVER=file` is the default here, and the file store is not taggable.
 * Every tagged `remember()` therefore fell through to "return the value
 * uncached" - so the product list, the category list, racks, shelves, brands,
 * units and manufacturers were recomputed on every request, and had been for as
 * long as the driver was anything other than Redis.
 *
 * These run against the file store itself, so they exercise exactly the branch
 * the application takes.
 */
class CacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The file store, not the array store: `array` is taggable in modern
        // Laravel, so it would exercise the tagged branch and prove nothing
        // about the one this file is here for. `file` is what this app is
        // configured with, and it cannot tag.
        config(['cache.default' => 'file']);
        Cache::store('file')->flush();
    }

    public function test_the_store_under_test_really_cannot_tag(): void
    {
        // If this ever fails the rest of the file is testing the wrong branch.
        $this->assertFalse(
            Cache::getStore() instanceof \Illuminate\Cache\TaggableStore,
        );
    }

    public function test_a_tagged_value_is_actually_cached(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return 'payload';
        };

        CacheService::remember('products_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]);
        CacheService::remember('products_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]);
        CacheService::remember('products_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]);

        $this->assertSame(1, $calls, 'The callback ran on every request before this fix.');
    }

    public function test_clearing_the_tag_invalidates_it(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return 'payload';
        };

        CacheService::remember('products_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]);
        CacheService::clearProductsCache();
        CacheService::remember('products_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]);

        $this->assertSame(2, $calls);
    }

    public function test_cold_product_page_is_built_once_then_served_without_a_lock(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return ['products' => [1, 2]];
        };

        $this->assertSame(['products' => [1, 2]], CacheService::rememberUnderLoad(
            'products_business_1_page_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]
        ));
        $this->assertSame(['products' => [1, 2]], CacheService::rememberUnderLoad(
            'products_business_1_page_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]
        ));
        $this->assertSame(1, $calls);

        CacheService::clearProductsCache();
        CacheService::rememberUnderLoad('products_business_1_page_1', $resolve, 3600, [CacheService::TAG_PRODUCTS]);
        $this->assertSame(2, $calls);
    }

    public function test_lock_contention_falls_back_instead_of_failing_a_read(): void
    {
        $lock = Cache::lock('rebuild:contended_page', 10);
        $this->assertTrue($lock->get());

        try {
            $this->assertSame('ready', CacheService::rememberUnderLoad(
                'contended_page', fn () => 'ready', 60
            ));
        } finally {
            $lock->release();
        }
    }

    public function test_clearing_one_tag_does_not_disturb_another(): void
    {
        $categoryCalls = 0;
        $resolve = function () use (&$categoryCalls) {
            $categoryCalls++;

            return 'categories';
        };

        CacheService::remember('categories_1', $resolve, 3600, [CacheService::TAG_CATEGORIES]);
        CacheService::clearProductsCache();
        CacheService::remember('categories_1', $resolve, 3600, [CacheService::TAG_CATEGORIES]);

        $this->assertSame(1, $categoryCalls);
    }

    public function test_two_businesses_do_not_share_a_cached_value(): void
    {
        $one = CacheService::rememberBusiness(1, 'racks', fn () => 'business one racks');
        $two = CacheService::rememberBusiness(2, 'racks', fn () => 'business two racks');

        $this->assertSame('business one racks', $one);
        $this->assertSame('business two racks', $two, 'A tenant must not read another tenant\'s cache.');
    }

    public function test_clearing_one_businesss_cache_leaves_another_alone(): void
    {
        CacheService::rememberBusiness(1, 'racks', fn () => 'one');
        CacheService::rememberBusiness(2, 'racks', fn () => 'two');

        CacheService::clearBusinessCache(1);

        $twoCalls = 0;
        CacheService::rememberBusiness(2, 'racks', function () use (&$twoCalls) {
            $twoCalls++;

            return 'two';
        });

        $this->assertSame(0, $twoCalls, 'Business two was still cached.');
    }

    public function test_an_untagged_value_still_caches_as_before(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return 'plain';
        };

        CacheService::remember('plain_key', $resolve);
        CacheService::remember('plain_key', $resolve);

        $this->assertSame(1, $calls);
    }
}

<?php

namespace App\Services;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class CacheService
{
    /**
     * Cache tags for different data types.
     */
    const TAG_PRODUCTS = 'products';
    const TAG_CATEGORIES = 'categories';
    const TAG_SETTINGS = 'settings';
    const TAG_USERS = 'users';
    const TAG_PERMISSIONS = 'permissions';
    const TAG_ROLES = 'roles';
    const TAG_BUSINESS = 'business';
    const TAG_CURRENCIES = 'currencies';
    const TAG_VATS = 'vats';
    const TAG_MASTER_DATA = 'master_data';

    /**
     * Get or cache data with tags.
     */
    public static function remember(string $key, $value, ?int $ttl = null, array $tags = [])
    {
        $ttl = $ttl ?? config('pos.cache.ttl', 3600);
        $key = self::normalizeKey($key);

        if (!empty($tags) && config('pos.cache.enabled', true) && self::supportsTags()) {
            return Cache::tags($tags)->remember($key, $ttl, $value);
        }

        if (!empty($tags)) {
            // Tags on a store that cannot tag.
            //
            // This used to return the value uncached, which meant that on the
            // file and database stores - `CACHE_DRIVER=file` being the default
            // here - every tagged call was a straight passthrough. The product
            // list, the category list, racks, shelves, brands, units and
            // manufacturers were all recomputed on every single request, and had
            // been for as long as the driver was not Redis.
            //
            // Folding a per-tag version stamp into the key caches them properly
            // and keeps them invalidatable: `clearTag()` bumps the stamp, which
            // strands every key carrying it at once, however many filter
            // permutations were cached.
            return Cache::remember(self::versionedKey($key, $tags), $ttl, $value);
        }

        return Cache::remember($key, $ttl, $value);
    }

    /**
     * Keep concurrent cold requests from rebuilding the same expensive page.
     * Warm reads never acquire a lock. On a busy or unavailable lock the normal
     * cache path remains available, so the request is not failed by contention.
     */
    public static function rememberUnderLoad(string $key, $value, ?int $ttl = null, array $tags = [])
    {
        $key = self::normalizeKey($key);
        $taggable = $tags !== [] && config('pos.cache.enabled', true) && self::supportsTags();
        $cache = $taggable ? Cache::tags($tags) : Cache::store();
        $effectiveKey = $tags !== [] && ! $taggable ? self::versionedKey($key, $tags) : $key;

        $cached = $cache->get($effectiveKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            return Cache::lock('rebuild:'.$effectiveKey, 30)->block(3, function () use ($key, $value, $ttl, $tags) {
                // Resolve the tag version again after waiting: a sale may have
                // invalidated stock while this request queued for the lock.
                return self::remember($key, $value, $ttl, $tags);
            });
        } catch (LockTimeoutException) {
            return self::remember($key, $value, $ttl, $tags);
        }
    }

    /**
     * A key that changes whenever any of its tags is cleared.
     */
    protected static function versionedKey(string $key, array $tags): string
    {
        $stamps = [];

        foreach ($tags as $tag) {
            $stamps[] = $tag . self::tagVersion($tag);
        }

        // Hashed so a long tag list cannot push the key past a store's limit.
        return self::normalizeKey($key . ':v' . substr(md5(implode('|', $stamps)), 0, 12));
    }

    protected static function tagVersion(string $tag): int
    {
        return (int) Cache::get(self::tagVersionKey($tag), 1);
    }

    protected static function tagVersionKey(string $tag): string
    {
        return self::normalizeKey('cachetag:' . $tag);
    }

    /**
     * Moves a tag on, stranding every key that carried its previous stamp.
     */
    protected static function bumpTagVersion(string $tag): void
    {
        $key = self::tagVersionKey($tag);

        // `add` seeds the counter; `increment` is atomic once it exists but
        // returns false on a store that cannot increment a missing key.
        if (Cache::add($key, 2, now()->addYear())) {
            return;
        }

        if (Cache::increment($key) === false) {
            Cache::put($key, self::tagVersion($tag) + 1, now()->addYear());
        }
    }

    /**
     * Get or cache data forever with tags.
     */
    public static function rememberForever(string $key, $value, array $tags = [])
    {
        $key = self::normalizeKey($key);

        if (!empty($tags) && config('pos.cache.enabled', true) && self::supportsTags()) {
            return Cache::tags($tags)->rememberForever($key, $value);
        }

        if (!empty($tags)) {
            // Same reasoning as `remember()`: cache it, keyed by tag version.
            return Cache::rememberForever(self::versionedKey($key, $tags), $value);
        }

        return Cache::rememberForever($key, $value);
    }

    /**
     * Forget a specific cache key.
     */
    public static function forget(string $key): bool
    {
        return Cache::forget(self::normalizeKey($key));
    }

    /**
     * Cache tenant-owned API data without leaking records across businesses.
     */
    public static function rememberBusiness(int $businessId, string $key, $value, ?int $ttl = null, array $tags = [])
    {
        if ($businessId < 1) {
            throw new InvalidArgumentException('Business id is required for tenant cache keys.');
        }

        return self::remember(
            self::businessKey($key, $businessId),
            $value,
            $ttl,
            array_values(array_unique(array_merge([self::businessTag($businessId)], $tags)))
        );
    }

    /**
     * Forget tenant-owned API data.
     */
    public static function forgetBusiness(int $businessId, string $key): bool
    {
        // Deleting the plain key was not enough, and on this deployment it did
        // nothing at all.
        //
        // `rememberBusiness()` always writes *with* tags, and `remember()` puts
        // a tagged entry somewhere the plain key does not address: under
        // `Cache::tags(...)` on a store that supports them, and under
        // `versionedKey()` — the key plus a hash of the tag stamps — on one that
        // does not. This deleted `normalizeKey($key)` in both cases, which is a
        // third address that was never written to.
        //
        // The visible symptom: a unit, brand, category, rack or shelf created
        // from the till stayed missing from its own list for the full 24-hour
        // TTL. The record existed — the duplicate-name check found it — so the
        // list said "no matching units" for something the form refused to
        // create again.
        //
        // Clearing the business tag strands every cached list for that business
        // whichever way it was stored. It invalidates more than the one key,
        // which costs a rebuild on the next read and is the right trade against
        // serving a write-back list that is a day out of date.
        self::forget(self::businessKey($key, $businessId));

        return self::clearTag(self::businessTag($businessId));
    }

    /**
     * Clear cache by tag.
     */
    public static function clearTag(string $tag): bool
    {
        if (config('pos.cache.enabled', true) && self::supportsTags()) {
            return Cache::tags([$tag])->flush();
        }

        // Returned false and did nothing at all on a store without tag support,
        // so every `clearProductsCache()` in the codebase was a no-op there.
        self::bumpTagVersion($tag);

        return true;
    }

    /**
     * Clear multiple cache tags.
     */
    public static function clearTags(array $tags): bool
    {
        if (config('pos.cache.enabled', true) && self::supportsTags()) {
            return Cache::tags($tags)->flush();
        }

        foreach ($tags as $tag) {
            self::bumpTagVersion($tag);
        }

        return true;
    }

    /**
     * Clear all application cache.
     */
    public static function clear(): bool
    {
        return Cache::flush();
    }

    /**
     * Get business-specific cache key.
     */
    public static function businessKey(string $key, int $businessId): string
    {
        return self::normalizeKey("business:{$businessId}:{$key}");
    }

    /**
     * Get business-specific cache tag.
     */
    public static function businessTag(int $businessId): string
    {
        return "business:{$businessId}";
    }

    /**
     * Get user-specific cache key.
     */
    public static function userKey(string $key, int $userId): string
    {
        return self::normalizeKey("user:{$userId}:{$key}");
    }

    /**
     * Clear business-related cache.
     */
    public static function clearBusinessCache(int $businessId): void
    {
        self::clearTags([
            self::businessTag($businessId),
            self::TAG_PRODUCTS,
            self::TAG_CATEGORIES,
            self::TAG_SETTINGS,
            self::TAG_BUSINESS,
            self::TAG_MASTER_DATA,
        ]);
    }

    /**
     * Clear user-related cache.
     */
    public static function clearUserCache(int $userId): void
    {
        self::clearTags([
            self::TAG_USERS,
            self::TAG_PERMISSIONS,
            self::TAG_ROLES,
        ]);
    }

    /**
     * Clear settings cache.
     */
    public static function clearSettingsCache(): void
    {
        self::clearTag(self::TAG_SETTINGS);
    }

    /**
     * Clear products cache.
     */
    public static function clearProductsCache(): void
    {
        self::clearTag(self::TAG_PRODUCTS);
    }

    /**
     * Clear categories cache.
     */
    public static function clearCategoriesCache(): void
    {
        self::clearTag(self::TAG_CATEGORIES);
    }

    /**
     * Check if the configured cache store supports tags.
     */
    protected static function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    /**
     * Keep cache keys stable and safe for stores with stricter key handling.
     */
    protected static function normalizeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9:_\-.]/', '_', trim($key));
    }
}

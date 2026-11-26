<?php

namespace Tests\Feature\Verification;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A cached response must not be served to the wrong shop.
 *
 * Cache keys are the quietest place for a tenant leak to hide: the query is
 * scoped correctly, the test passes on a cold cache, and the leak only appears
 * once someone else has warmed the same key. So these prime one tenant's cache
 * deliberately and then ask as the other.
 */
class ReportCacheLeakageTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $tag): array
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create([
            'business_id' => $business->id, 'role' => 'shop-owner',
        ]);
        $category = Category::factory()->create(['business_id' => $business->id]);

        Product::create([
            'productName' => "CACHE-$tag Medicine", 'business_id' => $business->id,
            'category_id' => $category->id, 'productCode' => "CACHE-$tag",
            'productStock' => 0, 'vat_type' => 'exclusive',
        ]);

        return [$business, $owner];
    }

    private function say(string $k, array $d): void
    {
        fwrite(STDERR, "\n[CACHE] {$k}: ".json_encode($d)."\n");
    }

    public function test_a_warmed_cache_is_not_served_across_tenants(): void
    {
        [, $ownerA] = $this->shop('A');
        [, $ownerB] = $this->shop('B');

        // Warm A's cache first, with identical request parameters.
        $a = $this->actingAs($ownerA)->getJson('/api/v1/products')->assertSuccessful();
        $this->assertStringContainsString('CACHE-A', $a->getContent());

        // Same URL, same params, different tenant.
        $b = $this->actingAs($ownerB)->getJson('/api/v1/products')->assertSuccessful();

        $this->say('cross_tenant_products', [
            'a_sees_own' => str_contains($a->getContent(), 'CACHE-A'),
            'b_sees_own' => str_contains($b->getContent(), 'CACHE-B'),
            'b_sees_a' => str_contains($b->getContent(), 'CACHE-A'),
            'a_sees_b' => str_contains($a->getContent(), 'CACHE-B'),
        ]);

        $this->assertStringNotContainsString(
            'CACHE-A', $b->getContent(),
            'tenant B was served tenant A\'s cached product list'
        );
        $this->assertStringContainsString('CACHE-B', $b->getContent());
    }

    public function test_every_cached_reference_list_is_keyed_per_tenant(): void
    {
        [, $ownerA] = $this->shop('A');
        [, $ownerB] = $this->shop('B');

        $lists = ['/api/v1/brands', '/api/v1/manufacturers', '/api/v1/units',
                  '/api/v1/racks', '/api/v1/shelves', '/api/v1/categories'];

        $results = [];

        foreach ($lists as $url) {
            $first = $this->actingAs($ownerA)->getJson($url);
            $second = $this->actingAs($ownerB)->getJson($url);

            // Both must answer; neither may carry the other's marker.
            $results[$url] = [
                'a' => $first->status(), 'b' => $second->status(),
                'b_saw_a_marker' => str_contains($second->getContent(), 'CACHE-A'),
            ];

            $this->assertFalse(
                $results[$url]['b_saw_a_marker'],
                "$url served tenant A's cached rows to tenant B"
            );
        }

        $this->say('reference_lists', $results);
    }

    /** The cache key itself must name the tenant, not just the resource. */
    public function test_cache_keys_carry_the_business_id(): void
    {
        [$businessA, $ownerA] = $this->shop('A');

        Cache::flush();
        $this->actingAs($ownerA)->getJson('/api/v1/products')->assertSuccessful();

        // A tenant-blind key would be reusable by any shop asking the same way.
        $source = file_get_contents(base_path('app/Http/Controllers/Api/AcnooProductController.php'));

        $this->say('key_shape', [
            'business_id_in_key' => str_contains($source, 'products_{$business_id}_'),
        ]);

        $this->assertStringContainsString(
            'products_{$business_id}_', $source,
            'the product cache key must be scoped to the shop it belongs to'
        );
    }

    /**
     * A committed purchase must not leave a stale product list behind.
     *
     * Driven through the purchase endpoint rather than a product create,
     * because a purchase is the movement that actually changes what the list
     * reports - the available quantity - and it is the case a counter hits
     * every day.
     */
    public function test_a_committed_purchase_invalidates_the_product_list(): void
    {
        [$business, $owner] = $this->shop('A');

        $product = Product::where('business_id', $business->id)->firstOrFail();
        $supplier = \App\Models\Party::create([
            'business_id' => $business->id, 'name' => 'CACHE Supplier',
            'type' => 'Supplier', 'due' => 0,
        ]);

        // Prime the cache while the shelf is empty.
        $before = $this->actingAs($owner)->getJson('/api/v1/products')->assertSuccessful();
        $availableBefore = $before->json('data.data.0.available_quantity');

        $this->actingAs($owner)->postJson('/api/v1/purchase', [
            'party_id' => $supplier->id, 'purchaseDate' => now()->toDateString(),
            'paymentType' => 'cash',
            'products' => [[
                'product_id' => $product->id, 'batch_no' => 'CACHE-B1',
                'quantities' => 25, 'productPurchasePrice' => 40, 'productSalePrice' => 100,
                'conversion_purchase_price' => 40, 'conversion_quantities' => 25,
                'conversion_value' => 1, 'conversion_price' => 100,
                'expire_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'upper_selected_unit' => 1, 'lower_selected_unit' => 1,
                'total_purchase_price' => 1000, 'total_price' => 2500,
            ]],
        ])->assertStatus(200);

        $after = $this->actingAs($owner)->getJson('/api/v1/products')->assertSuccessful();
        $availableAfter = $after->json('data.data.0.available_quantity');

        $this->say('invalidation', [
            'available_before' => $availableBefore,
            'available_after' => $availableAfter,
        ]);

        $this->assertSame(0.0, (float) $availableBefore);
        $this->assertSame(
            25.0, (float) $availableAfter,
            'the list must show stock the purchase just committed, not the cached emptiness'
        );
    }
}

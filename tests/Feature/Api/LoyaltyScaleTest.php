<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Customer;
use App\Models\LoyaltyPackage;
use App\Models\User;
use App\Services\LoyaltyDiscount;
use App\Services\LoyaltyEligibility;
use App\Services\LoyaltyPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The loyalty lookups a busy till performs, at the size a real shop reaches.
 *
 * A medical store carries tens of thousands of products, and the customer lookup
 * runs on the busiest screen in the app. Anything proportional to catalogue size
 * there is felt immediately — and felt worst on the slow connections these shops
 * actually run on.
 */
class LoyaltyScaleTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Category $category;
    protected Brand $brand;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->brand = Brand::factory()->create(['business_id' => $this->business->id]);

        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($this->user);
    }

    /**
     * Inserts [count] products in bulk, returning their ids.
     *
     * The ids are returned rather than assumed: MySQL does not roll back its
     * auto-increment counter between tests, so the first product is rarely id 1.
     *
     * @return array<int, int>
     */
    private function seedProducts(int $count): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'productName' => 'Item ' . $i,
                'productCode' => 'I' . $i,
                'business_id' => $this->business->id,
                'category_id' => $this->category->id,
                'brand_id'    => $this->brand->id,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('products')->insert($chunk);
        }

        return DB::table('products')
            ->where('business_id', $this->business->id)
            ->orderByDesc('id')
            ->limit($count)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Counts the queries [work] issues, from a clean log. */
    private function queriesFor(callable $work): int
    {
        // Flushed first: the log accumulates across enable/disable, so a second
        // measurement would otherwise include the first.
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    private function exclude(string $type, int $id): void
    {
        DB::table('loyalty_exclusions')->insert([
            'business_id'     => $this->business->id,
            'excludable_type' => $type,
            'excludable_id'   => $id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * The common case: no exclusions at all.
     *
     * It must cost one cheap query and touch no products, however large the
     * catalogue — most shops never configure an exclusion.
     */
    public function test_a_shop_with_no_exclusions_does_not_read_the_catalogue(): void
    {
        $this->seedProducts(2000);

        $excluded = [];
        $queries = $this->queriesFor(function () use (&$excluded) {
            $excluded = LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id);
        });

        $this->assertSame([], $excluded);
        $this->assertSame(1, $queries, 'one look at the rules, and nothing else');
    }

    /**
     * The regression this replaced: the previous version loaded every product id,
     * then every product row, then matched in PHP — so a customer lookup grew with
     * the catalogue.
     */
    public function test_the_work_does_not_grow_with_the_catalogue(): void
    {
        $ids = $this->seedProducts(2000);
        $this->exclude(LoyaltyEligibility::PRODUCT, $ids[0]);

        $small = $this->queriesFor(
            fn () => LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id),
        );

        $this->seedProducts(2000);

        $large = $this->queriesFor(
            fn () => LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id),
        );

        $this->assertSame(
            $small,
            $large,
            'doubling the catalogue must not cost another query',
        );
        $this->assertLessThanOrEqual(2, $large);
    }

    /** Only the excluded rows come back, not the catalogue that contains them. */
    public function test_it_returns_only_what_is_excluded(): void
    {
        $ids = $this->seedProducts(500);
        $this->exclude(LoyaltyEligibility::PRODUCT, $ids[0]);
        $this->exclude(LoyaltyEligibility::PRODUCT, $ids[1]);

        $excluded = LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id);

        $this->assertCount(2, $excluded);
        $this->assertEqualsCanonicalizing([$ids[0], $ids[1]], $excluded);
    }

    public function test_a_brand_rule_resolves_without_listing_products(): void
    {
        $ids = $this->seedProducts(100);

        $otherBrand = Brand::factory()->create(['business_id' => $this->business->id]);
        DB::table('products')
            ->whereIn('id', array_slice($ids, 0, 10))
            ->update(['brand_id' => $otherBrand->id]);

        $this->exclude(LoyaltyEligibility::BRAND, $otherBrand->id);

        $excluded = LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id);

        $this->assertCount(10, $excluded);
    }

    /**
     * An empty rule set for one type must contribute nothing rather than matching
     * every row — an `IN ()` that degenerates to "everything" would exclude the
     * whole catalogue and silently stop every loyalty discount.
     */
    public function test_one_rule_type_does_not_match_everything(): void
    {
        $ids = $this->seedProducts(50);
        $this->exclude(LoyaltyEligibility::CATEGORY, $this->category->id);

        $excluded = LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id);

        // Every product is in that category, so all 50 — but by the rule, not by
        // an empty clause collapsing.
        $this->assertCount(50, $excluded);

        DB::table('loyalty_exclusions')->delete();
        $this->exclude(LoyaltyEligibility::PRODUCT, $ids[0]);

        $this->assertCount(
            1,
            LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id),
            'a product rule must not drag in brand or category matches',
        );
    }

    public function test_another_businesses_products_are_never_returned(): void
    {
        $this->seedProducts(20);

        $other = Business::factory()->create();
        DB::table('products')->insert([
            'productName' => 'Theirs',
            'productCode' => 'T-1',
            'business_id' => $other->id,
            'category_id' => $this->category->id,
        ]);

        $this->exclude(LoyaltyEligibility::CATEGORY, $this->category->id);

        $excluded = LoyaltyEligibility::excludedProductIdsForBusiness($this->business->id);

        $this->assertCount(20, $excluded, 'the other shop\'s product is not ours to exclude');
    }

    // ── §12 reports at chain size ────────────────────────────────────────────

    private function package(): LoyaltyPackage
    {
        return LoyaltyPackage::create([
            'business_id'     => $this->business->id,
            'code'            => 'SCALE',
            'name'            => 'Scale tier',
            'cost'            => 100,
            'validity_type'   => LoyaltyPeriod::DAYS,
            'validity_period' => 365,
            'discount_type'   => LoyaltyDiscount::PERCENTAGE,
            'discount_value'  => 10,
            'discount_mode'   => LoyaltyDiscount::AUTOMATIC,
            'priority'        => 1,
        ]);
    }

    /** Creates [count] members, each with one subscription. */
    private function seedMembers(int $count, array $overrides = []): LoyaltyPackage
    {
        $package = $this->package();
        $customers = [];

        for ($i = 1; $i <= $count; $i++) {
            $customers[] = [
                'business_id' => $this->business->id,
                'name'        => 'Member ' . $i,
                'phone'       => '90000' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            ];
        }

        DB::table('customers')->insert($customers);

        $ids = DB::table('customers')
            ->where('business_id', $this->business->id)
            ->orderByDesc('id')
            ->limit($count)
            ->pluck('id');

        $subscriptions = [];

        foreach ($ids as $index => $id) {
            $subscriptions[] = $overrides + [
                'business_id'        => $this->business->id,
                'customer_id'        => $id,
                'loyalty_package_id' => $package->id,
                'subscription_date'  => now()->toDateString(),
                'start_date'         => now()->toDateString(),
                'end_date'           => now()->addDays(365)->toDateString(),
                'package_cost'       => 100,
                'amount_received'    => 100,
                'payment_mode'       => $index % 2 === 0 ? 'cash' : 'upi',
                'discount_type'      => LoyaltyDiscount::PERCENTAGE,
                'discount_value'     => 10,
                'status'             => LoyaltyPeriod::ACTIVE,
                'origin'             => 'new',
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
        }

        DB::table('loyalty_subscriptions')->insert($subscriptions);

        return $package;
    }

    private function report(string $name, array $query = [])
    {
        return $this->getJson('/api/v1/loyalty-reports/' . $name . '?' . http_build_query($query));
    }

    /**
     * A chain does not receive its whole membership in one response.
     *
     * The report is most needed at the size where sending all of it fails.
     */
    public function test_the_customer_report_is_paged(): void
    {
        $this->seedMembers(120);

        $response = $this->report('customers', ['per_page' => 25]);

        $response->assertOk();
        $this->assertCount(25, $response->json('data'));
        $this->assertSame(120, $response->json('meta.total'));
        $this->assertSame(5, $response->json('meta.last_page'));
    }

    /** A caller cannot ask for the whole membership by naming a huge page. */
    public function test_the_page_size_is_capped(): void
    {
        $this->seedMembers(60);

        $response = $this->report('customers', ['per_page' => 100000]);

        $response->assertOk();
        $this->assertSame(200, $response->json('meta.per_page'));
    }

    /**
     * The totals are computed by the database over everything the filters match,
     * so they must still agree with adding the rows up by hand.
     */
    public function test_the_money_report_totals_the_whole_set_not_a_page(): void
    {
        $this->seedMembers(120);

        $response = $this->report('amount-received');

        $response->assertOk();

        // Compared loosely on purpose: a whole rupee amount is JSON-encoded as an
        // integer, so an identity check would be asserting the encoding rather than
        // the arithmetic.
        // 120 members at 100 each, all of origin "new".
        $this->assertEquals(12000, $response->json('data.net_amount_received'));
        $this->assertEquals(12000, $response->json('data.new_subscription_amount'));
        $this->assertEquals(0, $response->json('data.renewal_amount'));

        // Split evenly between the two payment modes by the seeder.
        $this->assertEquals(6000, $response->json('data.by_payment_mode.cash'));
        $this->assertEquals(6000, $response->json('data.by_payment_mode.upi'));
    }

    /** Aggregating money must not cost a row per subscription. */
    public function test_the_money_report_does_not_read_every_subscription(): void
    {
        $this->seedMembers(400);

        $queries = $this->queriesFor(fn () => $this->report('amount-received'));

        $this->assertLessThan(
            15,
            $queries,
            'the totals are grouped by the database, not folded in PHP',
        );
    }

    /**
     * §12.2's "renewal pending" filter is applied by the database now. Filtering
     * after paging would return short pages and a total that counted customers the
     * caller asked to exclude.
     */
    public function test_renewal_pending_is_filtered_before_paging(): void
    {
        $package = $this->seedMembers(30, [
            'end_date' => now()->subDays(10)->toDateString(),
            'status'   => LoyaltyPeriod::EXPIRED,
        ]);

        // Ten of them have since taken a live package again.
        $renewed = DB::table('loyalty_subscriptions')
            ->where('business_id', $this->business->id)
            ->limit(10)
            ->pluck('customer_id');

        foreach ($renewed as $customerId) {
            DB::table('loyalty_subscriptions')->insert([
                'business_id'        => $this->business->id,
                'customer_id'        => $customerId,
                'loyalty_package_id' => $package->id,
                'subscription_date'  => now()->toDateString(),
                'start_date'         => now()->subDay()->toDateString(),
                'end_date'           => now()->addDays(300)->toDateString(),
                'package_cost'       => 100,
                'amount_received'    => 100,
                'discount_type'      => LoyaltyDiscount::PERCENTAGE,
                'discount_value'     => 10,
                'status'             => LoyaltyPeriod::ACTIVE,
                'origin'             => 'renewal',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $response = $this->report('expired', [
            'renewal_pending_only' => 1,
            'per_page'             => 100,
        ]);

        $response->assertOk();
        $this->assertSame(
            20,
            $response->json('meta.total'),
            'the ten who renewed are excluded by the query, so the total knows it',
        );
        $this->assertCount(20, $response->json('data'));
    }

    /** Every row on the page must still be a lapsed customer who has not returned. */
    public function test_the_expired_report_still_says_who_renewed(): void
    {
        $this->seedMembers(5, [
            'end_date' => now()->subDays(10)->toDateString(),
            'status'   => LoyaltyPeriod::EXPIRED,
        ]);

        $response = $this->report('expired');

        $response->assertOk();
        $this->assertCount(5, $response->json('data'));

        foreach ($response->json('data') as $row) {
            $this->assertSame('pending', $row['renewal_status']);
            $this->assertSame(10, $row['days_since_expiry']);
        }
    }
}

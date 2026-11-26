<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\LoyaltyPackage;
use App\Models\Product;
use App\Models\User;
use App\Services\LoyaltyEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Configuring what a loyalty discount does not apply to (§11).
 *
 * The engine had these rules before there was any way to enter them, which meant
 * every line stayed eligible in practice. These endpoints are what make the
 * configuration reachable.
 */
class LoyaltyExclusionEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $owner;
    protected Product $product;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->product = Product::create([
            'productName' => 'Prescription item',
            'productCode' => 'RX-1',
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
        ]);

        $this->actingAs($this->owner);
    }

    public function test_a_product_can_be_excluded(): void
    {
        $this->postJson('/api/v1/loyalty-exclusions', [
            'type'          => LoyaltyEligibility::PRODUCT,
            'excludable_id' => $this->product->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('loyalty_exclusions', [
            'business_id'     => $this->business->id,
            'excludable_type' => LoyaltyEligibility::PRODUCT,
            'excludable_id'   => $this->product->id,
        ]);
    }

    /** The list is unreadable as bare ids, so the name is resolved server-side. */
    public function test_the_list_names_what_it_excludes(): void
    {
        $this->postJson('/api/v1/loyalty-exclusions', [
            'type'          => LoyaltyEligibility::PRODUCT,
            'excludable_id' => $this->product->id,
        ])->assertStatus(200);

        $row = $this->getJson('/api/v1/loyalty-exclusions')
            ->assertStatus(200)->json('data.0');

        $this->assertSame('Prescription item', $row['name']);
        $this->assertSame('all_packages', $row['scope']);
    }

    public function test_an_exclusion_can_be_scoped_to_one_package(): void
    {
        $package = LoyaltyPackage::create([
            'business_id' => $this->business->id,
            'code' => 'SILVER', 'name' => 'Silver', 'cost' => 100,
            'validity_type' => 'days', 'validity_period' => 365,
            'discount_type' => 'percentage', 'discount_value' => 10,
            'discount_mode' => 'automatic', 'priority' => 1,
        ]);

        $this->postJson('/api/v1/loyalty-exclusions', [
            'type'               => LoyaltyEligibility::CATEGORY,
            'excludable_id'      => $this->category->id,
            'loyalty_package_id' => $package->id,
        ])->assertStatus(200);

        $row = $this->getJson('/api/v1/loyalty-exclusions')->json('data.0');

        $this->assertSame('package', $row['scope']);
        $this->assertSame($package->id, $row['loyalty_package_id']);
    }

    /**
     * An id belonging to another shop would create a rule that silently matches
     * nothing — worse than an error, because it looks configured.
     */
    public function test_an_item_from_another_business_is_refused(): void
    {
        $other = Business::factory()->create();
        $theirs = Product::create([
            'productName' => 'Not ours',
            'productCode' => 'X-1',
            'business_id' => $other->id,
        ]);

        $this->postJson('/api/v1/loyalty-exclusions', [
            'type'          => LoyaltyEligibility::PRODUCT,
            'excludable_id' => $theirs->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('loyalty_exclusions', 0);
    }

    /** Excluding something already excluded is the state the caller asked for. */
    public function test_excluding_twice_is_not_an_error(): void
    {
        $payload = [
            'type'          => LoyaltyEligibility::PRODUCT,
            'excludable_id' => $this->product->id,
        ];

        $this->postJson('/api/v1/loyalty-exclusions', $payload)->assertStatus(200);
        $this->postJson('/api/v1/loyalty-exclusions', $payload)->assertStatus(200);

        $this->assertDatabaseCount('loyalty_exclusions', 1);
    }

    public function test_an_exclusion_can_be_removed(): void
    {
        $this->postJson('/api/v1/loyalty-exclusions', [
            'type'          => LoyaltyEligibility::PRODUCT,
            'excludable_id' => $this->product->id,
        ])->assertStatus(200);

        $id = DB::table('loyalty_exclusions')->value('id');

        $this->deleteJson('/api/v1/loyalty-exclusions/' . $id)->assertStatus(200);

        $this->assertDatabaseCount('loyalty_exclusions', 0);
    }

    public function test_another_businesses_exclusion_cannot_be_removed(): void
    {
        $other = Business::factory()->create();

        $id = DB::table('loyalty_exclusions')->insertGetId([
            'business_id'     => $other->id,
            'excludable_type' => LoyaltyEligibility::PRODUCT,
            'excludable_id'   => 1,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->deleteJson('/api/v1/loyalty-exclusions/' . $id)->assertStatus(404);

        $this->assertDatabaseCount('loyalty_exclusions', 1);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->postJson('/api/v1/loyalty-exclusions', [
            'type'          => 'supplier',
            'excludable_id' => $this->product->id,
        ])->assertStatus(422);
    }

    /** §14: changing what is discounted is an auditable act. */
    public function test_adding_an_exclusion_is_recorded(): void
    {
        $this->postJson('/api/v1/loyalty-exclusions', [
            'type'          => LoyaltyEligibility::PRODUCT,
            'excludable_id' => $this->product->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('loyalty_audits', [
            'field'     => 'exclusion',
            'new_value' => LoyaltyEligibility::PRODUCT . ':' . $this->product->id,
        ]);
    }
}

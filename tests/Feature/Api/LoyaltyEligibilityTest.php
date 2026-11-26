<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Services\LoyaltyEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §11: which of a cart a loyalty discount may apply to.
 *
 * Without exclusions the discount lands on everything, including lines sold at or
 * below cost — prescription items, gift cards, third-party services. That loss is
 * invisible on the invoice and only shows up in the margin report, which is why it
 * needs to be configuration rather than a convention someone remembers.
 */
class LoyaltyEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected Category $category;
    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->brand = Brand::factory()->create(['business_id' => $this->business->id]);
    }

    private function product(array $overrides = []): Product
    {
        static $n = 0;
        $n++;

        return Product::create($overrides + [
            'productName' => 'Item ' . $n,
            'productCode' => 'I' . $n,
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
            'brand_id'    => $this->brand->id,
        ]);
    }

    private function stockFor(Product $product): Stock
    {
        return Stock::create([
            'business_id'  => $this->business->id,
            'product_id'   => $product->id,
            'batch_no'     => 'B' . $product->id,
            'productStock' => 100,
        ]);
    }

    private function package(string $code): \App\Models\LoyaltyPackage
    {
        return \App\Models\LoyaltyPackage::create([
            'business_id'     => $this->business->id,
            'code'            => $code,
            'name'            => $code,
            'cost'            => 100,
            'validity_type'   => 'days',
            'validity_period' => 365,
            'discount_type'   => 'percentage',
            'discount_value'  => 10,
            'discount_mode'   => 'automatic',
            'priority'        => 1,
        ]);
    }

    private function exclude(string $type, int $id, ?int $packageId = null): void
    {
        DB::table('loyalty_exclusions')->insert([
            'business_id'        => $this->business->id,
            'loyalty_package_id' => $packageId,
            'excludable_type'    => $type,
            'excludable_id'      => $id,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_everything_is_eligible_when_nothing_is_excluded(): void
    {
        $a = $this->product();
        $b = $this->product();

        $split = LoyaltyEligibility::split($this->business->id, [
            ['product_id' => $a->id, 'subtotal' => 600],
            ['product_id' => $b->id, 'subtotal' => 400],
        ]);

        $this->assertSame(1000.0, $split['eligible']);
        $this->assertSame(0.0, $split['ineligible']);
    }

    public function test_an_excluded_product_is_removed_from_the_base(): void
    {
        $ordinary = $this->product();
        $excluded = $this->product();

        $this->exclude(LoyaltyEligibility::PRODUCT, $excluded->id);

        $split = LoyaltyEligibility::split($this->business->id, [
            ['product_id' => $ordinary->id, 'subtotal' => 600],
            ['product_id' => $excluded->id, 'subtotal' => 400],
        ]);

        $this->assertSame(600.0, $split['eligible']);
        $this->assertSame(400.0, $split['ineligible']);
    }

    /** A whole brand can be excluded without listing its products. */
    public function test_a_brand_exclusion_covers_its_products(): void
    {
        $otherBrand = Brand::factory()->create(['business_id' => $this->business->id]);

        $branded = $this->product();
        $other   = $this->product(['brand_id' => $otherBrand->id]);

        $this->exclude(LoyaltyEligibility::BRAND, $this->brand->id);

        $split = LoyaltyEligibility::split($this->business->id, [
            ['product_id' => $branded->id, 'subtotal' => 500],
            ['product_id' => $other->id, 'subtotal' => 500],
        ]);

        $this->assertSame(500.0, $split['eligible']);
        $this->assertSame(500.0, $split['ineligible']);
    }

    public function test_a_category_exclusion_covers_its_products(): void
    {
        $otherCategory = Category::factory()->create(['business_id' => $this->business->id]);

        $inCategory = $this->product();
        $elsewhere  = $this->product(['category_id' => $otherCategory->id]);

        $this->exclude(LoyaltyEligibility::CATEGORY, $this->category->id);

        $split = LoyaltyEligibility::split($this->business->id, [
            ['product_id' => $inCategory->id, 'subtotal' => 300],
            ['product_id' => $elsewhere->id, 'subtotal' => 700],
        ]);

        $this->assertSame(700.0, $split['eligible']);
        $this->assertSame(300.0, $split['ineligible']);
    }

    /**
     * A sale line names a batch, not a product — the exclusion still has to find
     * it, or nothing would ever be excluded on a real sale.
     */
    public function test_a_line_identified_only_by_batch_is_still_matched(): void
    {
        $excluded = $this->product();
        $stock = $this->stockFor($excluded);

        $this->exclude(LoyaltyEligibility::PRODUCT, $excluded->id);

        $split = LoyaltyEligibility::split($this->business->id, [
            ['stock_id' => $stock->id, 'quantities' => 2, 'price' => 200],
        ]);

        $this->assertSame(0.0, $split['eligible']);
        $this->assertSame(400.0, $split['ineligible']);
    }

    /** A rule for one package must not silently apply to another. */
    public function test_a_package_specific_exclusion_does_not_leak(): void
    {
        $product = $this->product();
        $silver  = $this->package('SILVER');
        $gold    = $this->package('GOLD');

        $this->exclude(LoyaltyEligibility::PRODUCT, $product->id, packageId: $silver->id);

        $forSilver = LoyaltyEligibility::split(
            $this->business->id,
            [['product_id' => $product->id, 'subtotal' => 500]],
            packageId: $silver->id,
        );
        $forGold = LoyaltyEligibility::split(
            $this->business->id,
            [['product_id' => $product->id, 'subtotal' => 500]],
            packageId: $gold->id,
        );

        $this->assertSame(500.0, $forSilver['ineligible']);
        $this->assertSame(500.0, $forGold['eligible']);
    }

    /** A shop-wide rule applies whichever package the customer holds. */
    public function test_a_shop_wide_exclusion_applies_to_every_package(): void
    {
        $product = $this->product();

        $this->exclude(LoyaltyEligibility::PRODUCT, $product->id, packageId: null);

        $split = LoyaltyEligibility::split(
            $this->business->id,
            [['product_id' => $product->id, 'subtotal' => 500]],
            packageId: $this->package('ANY')->id,
        );

        $this->assertSame(500.0, $split['ineligible']);
    }

    /** §8.1: row discounts come off before the loyalty discount is calculated. */
    public function test_the_line_value_is_the_discounted_one(): void
    {
        $product = $this->product();

        $split = LoyaltyEligibility::split($this->business->id, [
            [
                'product_id' => $product->id,
                'price' => 100, 'quantities' => 10,
                'discountAmount' => 200,
            ],
        ]);

        $this->assertSame(800.0, $split['eligible']);
    }

    public function test_an_empty_cart_is_worth_nothing(): void
    {
        $split = LoyaltyEligibility::split($this->business->id, []);

        $this->assertSame(0.0, $split['eligible']);
        $this->assertSame(0.0, $split['ineligible']);
    }

    public function test_another_businesses_exclusions_do_not_apply(): void
    {
        $product = $this->product();
        $other = Business::factory()->create();

        DB::table('loyalty_exclusions')->insert([
            'business_id'     => $other->id,
            'excludable_type' => LoyaltyEligibility::PRODUCT,
            'excludable_id'   => $product->id,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $split = LoyaltyEligibility::split($this->business->id, [
            ['product_id' => $product->id, 'subtotal' => 500],
        ]);

        $this->assertSame(500.0, $split['eligible']);
    }
}

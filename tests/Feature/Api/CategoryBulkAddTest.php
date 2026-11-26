<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adding a whole category to the cart in one tap.
 *
 * The server decides the quantities rather than the client, because the server owns
 * availability — the app deciding for itself is what made items unsellable before.
 *
 * The rule that matters: a bulk add never offers more than exists, and never silently
 * drops an item. An order that is quietly half the size the user asked for is worse
 * than one that explains what it left out.
 */
class CategoryBulkAddTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->category = Category::factory()->create([
            'business_id'      => $this->business->id,
            'categoryName'     => 'Syrups',
            'bulk_add_enabled' => true,
            'default_quantity' => 20,
        ]);
    }

    /** Creates a product in the category with the given quantity in stock. */
    private function product(string $name, float $available): Product
    {
        $product = Product::create([
            'productName' => $name,
            'productCode' => strtoupper(substr(md5($name), 0, 6)),
            'business_id' => $this->business->id,
            'category_id' => $this->category->id,
        ]);

        $stock = Stock::create([
            'business_id' => $this->business->id,
            'product_id'  => $product->id,
            'batch_no'    => "B-$name",
        ]);

        if ($available > 0) {
            $purchaseId = DB::table('purchases')->insertGetId([
                'business_id' => $this->business->id,
                'totalAmount' => 100,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('purchase_details')->insert([
                'purchase_id'        => $purchaseId,
                'product_id'         => $product->id,
                'stock_id'           => $stock->id,
                'quantities'         => $available,
                'conversionValue'    => 1,
                'conversionQuantity' => $available,
                'purchaseUpperLimit' => '',
                'purchaseLowerLimit' => '',
            ]);
        }

        return $product;
    }

    private function bulkAdd(array $query = [])
    {
        return $this->actingAs($this->user)->getJson(
            "/api/v1/categories/{$this->category->id}/bulk-add?" . http_build_query($query)
        );
    }

    public function test_it_returns_every_stocked_item_at_the_default_quantity(): void
    {
        $this->product('Cough', 50);
        $this->product('Cold', 50);

        $this->bulkAdd()
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.requested_quantity', 20)
            ->assertJsonPath('data.lines.0.quantity', 20);
    }

    public function test_the_default_quantity_is_configurable(): void
    {
        $this->category->update(['default_quantity' => 5]);
        $this->product('Cough', 50);

        $this->bulkAdd()
            ->assertJsonPath('data.requested_quantity', 5)
            ->assertJsonPath('data.lines.0.quantity', 5);
    }

    public function test_a_caller_may_ask_for_a_different_quantity(): void
    {
        $this->product('Cough', 50);

        $this->bulkAdd(['quantity' => 7])
            ->assertJsonPath('data.lines.0.quantity', 7);
    }

    /** The rule: never offer more than exists. */
    public function test_the_quantity_is_capped_at_what_is_in_stock(): void
    {
        $this->product('Cough', 3);

        $this->bulkAdd()
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.lines.0.capped', true);
    }

    public function test_a_line_within_stock_is_not_flagged_as_capped(): void
    {
        $this->product('Cough', 50);

        $this->bulkAdd()->assertJsonPath('data.lines.0.capped', false);
    }

    /** Reported, not dropped: a quietly shorter order is worse than an explained one. */
    public function test_an_out_of_stock_item_is_reported_rather_than_omitted(): void
    {
        $this->product('Cough', 50);
        $this->product('Empty', 0);

        $response = $this->bulkAdd()->assertStatus(200);

        $this->assertCount(1, $response->json('data.lines'));
        $this->assertCount(1, $response->json('data.skipped'));
        $this->assertSame('out_of_stock', $response->json('data.skipped.0.reason'));
        $this->assertSame('Empty', $response->json('data.skipped.0.product_name'));
    }

    public function test_a_line_below_the_minimum_is_skipped_with_a_reason(): void
    {
        $this->category->update(['min_order_quantity' => 10]);
        // Only 4 in stock, so it can never reach a floor of 10.
        $this->product('Scarce', 4);

        $response = $this->bulkAdd()->assertStatus(200);

        $this->assertCount(0, $response->json('data.lines'));
        $this->assertSame('below_minimum', $response->json('data.skipped.0.reason'));
        $this->assertSame(10, $response->json('data.skipped.0.minimum_quantity'));
    }

    public function test_a_line_meeting_the_minimum_is_included(): void
    {
        $this->category->update(['min_order_quantity' => 10]);
        $this->product('Plenty', 50);

        $this->bulkAdd()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.min_order_quantity', 10);
    }

    public function test_a_category_not_set_up_for_bulk_add_is_refused(): void
    {
        $this->category->update(['bulk_add_enabled' => false]);
        $this->product('Cough', 50);

        $this->bulkAdd()
            ->assertStatus(409)
            ->assertJsonPath('reason', 'bulk_add_disabled');
    }

    public function test_bulk_add_is_off_by_default(): void
    {
        $fresh = Category::factory()->create(['business_id' => $this->business->id]);

        $this->assertFalse((bool) $fresh->bulk_add_enabled,
            'an existing category must keep behaving as it did');
    }

    public function test_the_documented_default_quantity_is_twenty(): void
    {
        $fresh = Category::factory()->create(['business_id' => $this->business->id]);

        $this->assertSame(20, (int) $fresh->default_quantity);
    }

    public function test_a_negative_requested_quantity_is_clamped(): void
    {
        $this->product('Cough', 50);

        $this->bulkAdd(['quantity' => -5])
            ->assertJsonPath('data.lines.0.quantity', 1);
    }

    public function test_another_businesses_category_is_refused(): void
    {
        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->getJson("/api/v1/categories/{$this->category->id}/bulk-add")
            ->assertStatus(403);
    }

    public function test_an_empty_category_returns_nothing_without_failing(): void
    {
        $this->bulkAdd()
            ->assertStatus(200)
            ->assertJsonPath('data.total_products', 0)
            ->assertJsonCount(0, 'data.lines');
    }
}

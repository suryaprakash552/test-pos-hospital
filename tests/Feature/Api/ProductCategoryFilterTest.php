<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Switching the category filter and switching back.
 *
 * The sequence a shop actually performs: look at everything, narrow to a
 * category, then go back to everything. The last step has to return the whole
 * catalogue again — reported as "change category and back to all, then not
 * showing all items".
 *
 * The index is cached per request signature, so this is as much a test of the
 * cache key as of the filter: two different questions must not share an answer.
 */
class ProductCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $user;
    private Category $opd;
    private Category $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);

        $this->opd = Category::factory()->create([
            'business_id' => $this->business->id,
            'categoryName' => 'Opd',
        ]);
        $this->schedule = Category::factory()->create([
            'business_id' => $this->business->id,
            'categoryName' => 'Shedule',
        ]);

        $this->product('Dummy2', $this->opd->id);
        $this->product('Thermal Printer Roll', $this->opd->id);
        $this->product('R/v One Week', $this->schedule->id);
        $this->product('Op200', $this->schedule->id);
        $this->product('Smart Iv Set', null);
        $this->product('Uncategorised Item', null);

        $this->actingAs($this->user);
    }

    private function product(string $name, ?int $categoryId): void
    {
        DB::table('products')->insert([
            'productName' => $name,
            'productCode' => strtoupper(substr(md5($name), 0, 8)),
            'business_id' => $this->business->id,
            'category_id' => $categoryId,
        ]);
    }

    /** One page of the index, optionally narrowed to a category. */
    private function index(?int $categoryId = null)
    {
        $query = ['per_page' => 30, 'page' => 1];

        if ($categoryId !== null) {
            $query['category_id'] = (string) $categoryId;
        }

        return $this->getJson('/api/v1/products?' . http_build_query($query));
    }

    private function names($response): array
    {
        return collect($response->json('data.data'))
            ->pluck('productName')
            ->sort()
            ->values()
            ->all();
    }

    public function test_everything_is_returned_with_no_filter(): void
    {
        $response = $this->index()->assertOk();

        $this->assertCount(6, $this->names($response));
    }

    public function test_a_category_narrows_the_list(): void
    {
        $response = $this->index($this->opd->id)->assertOk();

        $this->assertEqualsCanonicalizing(
            ['Dummy2', 'Thermal Printer Roll'],
            $this->names($response),
        );
    }

    /**
     * The reported sequence, in order. Going back to "All Products" has to give
     * the whole catalogue, not the category that was just being viewed.
     */
    public function test_going_back_to_all_returns_everything(): void
    {
        $before = $this->names($this->index()->assertOk());
        $this->assertCount(6, $before);

        $this->index($this->opd->id)->assertOk();

        $after = $this->names($this->index()->assertOk());

        $this->assertSame(
            $before,
            $after,
            'the unfiltered list must not be affected by having filtered',
        );
        $this->assertCount(6, $after);
    }

    /** And through several categories, which is how someone browses. */
    public function test_switching_between_categories_stays_correct(): void
    {
        $this->index()->assertOk();

        $this->assertCount(2, $this->names($this->index($this->opd->id)->assertOk()));
        $this->assertCount(2, $this->names($this->index($this->schedule->id)->assertOk()));
        $this->assertCount(2, $this->names($this->index($this->opd->id)->assertOk()));
        $this->assertCount(6, $this->names($this->index()->assertOk()));
    }

    /**
     * Two different questions must not share a cached answer. The index is
     * cached per request signature, and a key that ignored the filter would
     * serve one category's products as the whole catalogue.
     */
    public function test_a_filtered_answer_is_never_served_as_the_unfiltered_one(): void
    {
        $filtered = $this->names($this->index($this->opd->id)->assertOk());
        $all = $this->names($this->index()->assertOk());

        $this->assertNotSame($filtered, $all);
        $this->assertCount(6, $all);
    }

    /** Uncategorised products belong to no category, but do belong to "all". */
    public function test_uncategorised_products_appear_under_all(): void
    {
        $all = $this->names($this->index()->assertOk());

        $this->assertContains('Smart Iv Set', $all);
        $this->assertContains('Uncategorised Item', $all);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `products.category_id`, `brand_id`, `unit_id` and `manufacturer_id` were
 * created `cascadeOnDelete`. Deleting a category therefore deleted every product
 * in it — from a delete button sitting beside "rename" on a lookup list, with no
 * warning that it discards the catalogue.
 *
 * These tests pin the refusal. They assert the products survive, which is the
 * part that matters; the status code and wording are how the app explains it.
 */
class LookupDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'category_id' => Category::factory()->create([
                'business_id' => $this->business->id,
            ])->id,
        ], $attributes));
    }

    public function test_a_category_in_use_is_not_deleted(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $product = $this->product(['category_id' => $category->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(409);

        // The whole point: the catalogue is still there.
        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_the_refusal_names_how_many_products_are_in_the_way(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);
        $this->product(['category_id' => $category->id]);
        $this->product(['category_id' => $category->id]);
        $this->product(['category_id' => $category->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}");

        // A bare "could not delete" leaves the user with nothing to do next.
        $response->assertStatus(409);
        $this->assertStringContainsString('3', $response->json('message'));
    }

    public function test_an_unused_category_still_deletes(): void
    {
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_a_brand_in_use_is_not_deleted(): void
    {
        $brand = Brand::factory()->create(['business_id' => $this->business->id]);
        $product = $this->product(['brand_id' => $brand->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/brands/{$brand->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_an_unused_brand_still_deletes(): void
    {
        $brand = Brand::factory()->create(['business_id' => $this->business->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/brands/{$brand->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
    }

    public function test_a_unit_in_use_is_not_deleted(): void
    {
        $unit = Unit::factory()->create(['business_id' => $this->business->id]);
        $product = $this->product(['unit_id' => $unit->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/units/{$unit->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_manufacturer_in_use_is_not_deleted(): void
    {
        $manufacturer = Manufacturer::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $product = $this->product(['manufacturer_id' => $manufacturer->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/manufacturers/{$manufacturer->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_a_reference_from_another_business_still_blocks(): void
    {
        $otherBusiness = Business::factory()->create();
        $category = Category::factory()->create(['business_id' => $this->business->id]);

        // A cross-tenant reference should not exist — a category carries its own
        // business_id. Where one does, the FK constraint blocks the delete
        // regardless of who asks, so the guard has to agree with it. Scoping the
        // guard to the caller's business instead produced a 500 from the driver
        // rather than a 409 with an explanation.
        Product::factory()->create([
            'business_id' => $otherBusiness->id,
            'category_id' => $category->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_delete_success_message_names_the_record_type(): void
    {
        $brand = Brand::factory()->create(['business_id' => $this->business->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/brands/{$brand->id}");

        // Six endpoints all answered "Data deleted successfully.", and the app
        // shows the server's wording, so every one of these screens reported a
        // deletion in words that named nothing.
        $response->assertStatus(200);
        $this->assertStringContainsString('Brand', $response->json('message'));
    }
}

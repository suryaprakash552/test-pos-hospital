<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `AcnooCategoryController::update` wrote every column unconditionally, reading
 * an absent field as its zero value: `filter_var(null, FILTER_VALIDATE_BOOLEAN)`
 * is false, and the quantities fell back to their defaults.
 *
 * The app sends only the name and the variation flags when renaming a category,
 * so every rename silently switched bulk add off and reset default_quantity to
 * 20 and min_order_quantity to 0. Nothing on the screen mentioned bulk add, so
 * there was no way to connect the two.
 */
class CategoryPartialUpdateTest extends TestCase
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

    private function category(array $attributes = []): Category
    {
        return Category::factory()->create(array_merge([
            'business_id' => $this->business->id,
        ], $attributes));
    }

    public function test_renaming_does_not_disable_bulk_add(): void
    {
        $category = $this->category([
            'categoryName' => 'Drinks',
            'bulk_add_enabled' => true,
            'default_quantity' => 50,
            'min_order_quantity' => 5,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => 'Cold Drinks',
            ])
            ->assertStatus(200);

        $category->refresh();

        $this->assertSame('Cold Drinks', $category->categoryName);
        $this->assertTrue((bool) $category->bulk_add_enabled);
        $this->assertSame(50, (int) $category->default_quantity);
        $this->assertSame(5, (int) $category->min_order_quantity);
    }

    public function test_renaming_does_not_clear_variation_flags(): void
    {
        $category = $this->category([
            'variationSize' => true,
            'variationColor' => true,
            'variationCapacity' => true,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => 'Renamed',
            ])
            ->assertStatus(200);

        $category->refresh();

        $this->assertTrue((bool) $category->variationSize);
        $this->assertTrue((bool) $category->variationColor);
        $this->assertTrue((bool) $category->variationCapacity);
    }

    public function test_an_explicit_false_still_turns_a_flag_off(): void
    {
        // The fix must not make flags un-clearable: "absent" and "sent as false"
        // are different things.
        $category = $this->category(['variationSize' => true]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => $category->categoryName,
                'variationSize' => false,
            ])
            ->assertStatus(200);

        $this->assertFalse((bool) $category->refresh()->variationSize);
    }

    public function test_an_explicit_false_still_disables_bulk_add(): void
    {
        $category = $this->category(['bulk_add_enabled' => true]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => $category->categoryName,
                'bulk_add_enabled' => false,
            ])
            ->assertStatus(200);

        $this->assertFalse((bool) $category->refresh()->bulk_add_enabled);
    }

    public function test_quantities_update_when_sent(): void
    {
        $category = $this->category([
            'bulk_add_enabled' => true,
            'default_quantity' => 20,
            'min_order_quantity' => 0,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => $category->categoryName,
                'default_quantity' => 12,
                'min_order_quantity' => 3,
            ])
            ->assertStatus(200);

        $category->refresh();
        $this->assertSame(12, (int) $category->default_quantity);
        $this->assertSame(3, (int) $category->min_order_quantity);
    }

    public function test_a_default_quantity_of_zero_is_floored_to_one(): void
    {
        // A bulk add of zero silently adds nothing, which reads as the button
        // being broken.
        $category = $this->category(['default_quantity' => 20]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => $category->categoryName,
                'default_quantity' => 0,
            ])
            ->assertStatus(200);

        $this->assertSame(1, (int) $category->refresh()->default_quantity);
    }
}

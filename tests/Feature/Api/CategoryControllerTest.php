<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class CategoryControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected User $user;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner'
        ]);
    }

    public function test_can_list_categories()
    {
        Category::factory()->count(3)->create(['business_id' => $this->user->business_id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'categoryName', 'business_id']
                ]
            ])
            ->assertJsonPath('message', __('Data fetched successfully.'));
    }

    public function test_can_create_category()
    {
        $categoryData = [
            'categoryName' => 'Electronics',
            'variationCapacity' => true,
            'variationColor' => false,
            'variationSize' => false,
            'variationType' => false,
            'variationWeight' => false,
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', $categoryData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'categoryName', 'business_id']
            ])
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('categories', [
            'categoryName' => 'Electronics',
            'business_id' => $this->user->business_id,
        ]);
    }

    public function test_cannot_create_duplicate_category()
    {
        Category::factory()->create([
            'categoryName' => 'Electronics',
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'categoryName' => 'Electronics',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['categoryName']);
    }

    public function test_can_update_category()
    {
        $category = Category::factory()->create([
            'business_id' => $this->user->business_id,
            'categoryName' => 'Old Name'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => 'New Name',
                'variationCapacity' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'categoryName' => 'New Name',
        ]);
    }

    public function test_cannot_update_category_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $category = Category::factory()->create([
            'business_id' => $otherUser->business_id,
            'categoryName' => 'Other Business Category'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/categories/{$category->id}", [
                'categoryName' => 'Hacked Name',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_can_delete_category()
    {
        $category = Category::factory()->create([
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', __(':noun deleted.', ['noun' => __('Category')]));

        $this->assertDatabaseMissing('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_cannot_delete_category_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $category = Category::factory()->create([
            'business_id' => $otherUser->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_category_name_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'categoryName' => '<script>alert("xss")</script>Electronics',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('categories', [
            'business_id' => $this->user->business_id,
        ]);

        $category = Category::where('business_id', $this->user->business_id)->first();
        $this->assertStringNotContainsString('<script>', $category->categoryName);
    }

    public function test_boolean_fields_properly_cast()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/categories', [
                'categoryName' => 'Test Category',
                'variationCapacity' => 'true',
                'variationColor' => 'false',
            ]);

        $response->assertStatus(200);

        $category = Category::where('categoryName', 'Test Category')->first();
        $this->assertEquals(1, $category->variationCapacity);
        $this->assertEquals(0, $category->variationColor);
    }
}

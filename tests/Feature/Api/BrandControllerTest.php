<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class BrandControllerTest extends TestCase
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

    public function test_can_list_brands()
    {
        Brand::factory()->count(3)->create(['business_id' => $this->user->business_id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/brands');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'brandName', 'business_id']
                ]
            ])
            ->assertJsonPath('message', __('Data fetched successfully.'));
    }

    public function test_can_create_brand()
    {
        $brandData = [
            'brandName' => 'Nike',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/brands', $brandData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'brandName', 'business_id']
            ])
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('brands', [
            'brandName' => 'Nike',
            'business_id' => $this->user->business_id,
        ]);
    }

    public function test_cannot_create_duplicate_brand()
    {
        Brand::factory()->create([
            'brandName' => 'Nike',
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/brands', [
                'brandName' => 'Nike',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['brandName']);
    }

    public function test_can_update_brand()
    {
        $brand = Brand::factory()->create([
            'business_id' => $this->user->business_id,
            'brandName' => 'Old Brand'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/brands/{$brand->id}", [
                'brandName' => 'New Brand',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'brandName' => 'New Brand',
        ]);
    }

    public function test_cannot_update_brand_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $brand = Brand::factory()->create([
            'business_id' => $otherUser->business_id,
            'brandName' => 'Other Business Brand'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/brands/{$brand->id}", [
                'brandName' => 'Hacked Brand',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_can_delete_brand()
    {
        $brand = Brand::factory()->create([
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/brands/{$brand->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', __(':noun deleted.', ['noun' => __('Brand')]));

        $this->assertDatabaseMissing('brands', [
            'id' => $brand->id,
        ]);
    }

    public function test_cannot_delete_brand_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $brand = Brand::factory()->create([
            'business_id' => $otherUser->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/brands/{$brand->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_brand_name_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/brands', [
                'brandName' => '<script>alert("xss")</script>Adidas',
            ]);

        $response->assertStatus(200);

        $brand = Brand::where('business_id', $this->user->business_id)->first();
        $this->assertStringNotContainsString('<script>', $brand->brandName);
    }
}

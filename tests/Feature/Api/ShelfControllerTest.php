<?php

namespace Tests\Feature\Api;

use App\Models\Shelf;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ShelfControllerTest extends TestCase
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

    public function test_can_list_shelves()
    {
        Shelf::factory()->count(3)->create(['business_id' => $this->user->business_id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/shelfs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'name', 'business_id']
                ]
            ])
            ->assertJsonPath('message', __('Data fetched successfully.'));
    }

    public function test_can_create_shelf()
    {
        $shelfData = [
            'name' => 'Shelf 1',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/shelfs', $shelfData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'business_id']
            ])
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('shelfs', [
            'name' => 'Shelf 1',
            'business_id' => $this->user->business_id,
        ]);
    }

    public function test_cannot_create_duplicate_shelf()
    {
        Shelf::factory()->create([
            'name' => 'Shelf 1',
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/shelfs', [
                'name' => 'Shelf 1',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_update_shelf()
    {
        $shelf = Shelf::factory()->create([
            'business_id' => $this->user->business_id,
            'name' => 'Old Shelf'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/shelfs/{$shelf->id}", [
                'name' => 'New Shelf',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data updated successfully.'));

        $this->assertDatabaseHas('shelfs', [
            'id' => $shelf->id,
            'name' => 'New Shelf',
        ]);
    }

    public function test_cannot_update_shelf_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $shelf = Shelf::factory()->create([
            'business_id' => $otherUser->business_id,
            'name' => 'Other Business Shelf'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/shelfs/{$shelf->id}", [
                'name' => 'Hacked Shelf',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_can_delete_shelf()
    {
        $shelf = Shelf::factory()->create([
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/shelfs/{$shelf->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', __(':noun deleted.', ['noun' => __('Shelf')]));

        $this->assertDatabaseMissing('shelfs', [
            'id' => $shelf->id,
        ]);
    }

    public function test_cannot_delete_shelf_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $shelf = Shelf::factory()->create([
            'business_id' => $otherUser->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/shelfs/{$shelf->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_shelf_name_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/shelfs', [
                'name' => '<script>alert("xss")</script>Shelf 2',
            ]);

        $response->assertStatus(200);

        $shelf = Shelf::where('business_id', $this->user->business_id)->first();
        $this->assertStringNotContainsString('<script>', $shelf->name);
    }
}

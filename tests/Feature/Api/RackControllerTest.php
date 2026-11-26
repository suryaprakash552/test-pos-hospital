<?php

namespace Tests\Feature\Api;

use App\Models\Rack;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class RackControllerTest extends TestCase
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

    public function test_can_list_racks()
    {
        Rack::factory()->count(3)->create(['business_id' => $this->user->business_id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/racks');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'name', 'business_id']
                ]
            ])
            ->assertJsonPath('message', __('Data fetched successfully.'));
    }

    public function test_can_create_rack()
    {
        $rackData = [
            'name' => 'Rack A1',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/racks', $rackData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'business_id']
            ])
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('racks', [
            'name' => 'Rack A1',
            'business_id' => $this->user->business_id,
        ]);
    }

    public function test_cannot_create_duplicate_rack()
    {
        Rack::factory()->create([
            'name' => 'Rack A1',
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/racks', [
                'name' => 'Rack A1',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_update_rack()
    {
        $rack = Rack::factory()->create([
            'business_id' => $this->user->business_id,
            'name' => 'Old Rack'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/racks/{$rack->id}", [
                'name' => 'New Rack',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data updated successfully.'));

        $this->assertDatabaseHas('racks', [
            'id' => $rack->id,
            'name' => 'New Rack',
        ]);
    }

    public function test_cannot_update_rack_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $rack = Rack::factory()->create([
            'business_id' => $otherUser->business_id,
            'name' => 'Other Business Rack'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/racks/{$rack->id}", [
                'name' => 'Hacked Rack',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_can_delete_rack()
    {
        $rack = Rack::factory()->create([
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/racks/{$rack->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', __(':noun deleted.', ['noun' => __('Rack')]));

        $this->assertDatabaseMissing('racks', [
            'id' => $rack->id,
        ]);
    }

    public function test_cannot_delete_rack_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $rack = Rack::factory()->create([
            'business_id' => $otherUser->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/racks/{$rack->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_rack_name_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/racks', [
                'name' => '<script>alert("xss")</script>Rack B1',
            ]);

        $response->assertStatus(200);

        $rack = Rack::where('business_id', $this->user->business_id)->first();
        $this->assertStringNotContainsString('<script>', $rack->name);
    }
}

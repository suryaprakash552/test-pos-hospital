<?php

namespace Tests\Feature\Api;

use App\Models\Manufacturer;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ManufacturerControllerTest extends TestCase
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

    public function test_can_list_manufacturers()
    {
        Manufacturer::factory()->count(3)->create(['business_id' => $this->user->business_id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/manufacturers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'manufacturerName', 'business_id']
                ]
            ])
            ->assertJsonPath('message', __('Data fetched successfully.'));
    }

    public function test_can_create_manufacturer()
    {
        $manufacturerData = [
            'manufacturerName' => 'Sony',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/manufacturers', $manufacturerData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'manufacturerName', 'business_id']
            ])
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('manufacturers', [
            'manufacturerName' => 'Sony',
            'business_id' => $this->user->business_id,
        ]);
    }

    public function test_can_update_manufacturer()
    {
        $manufacturer = Manufacturer::factory()->create([
            'business_id' => $this->user->business_id,
            'manufacturerName' => 'Old Manufacturer'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/manufacturers/{$manufacturer->id}", [
                'manufacturerName' => 'New Manufacturer',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('manufacturers', [
            'id' => $manufacturer->id,
            'manufacturerName' => 'New Manufacturer',
        ]);
    }

    public function test_cannot_update_manufacturer_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $manufacturer = Manufacturer::factory()->create([
            'business_id' => $otherUser->business_id,
            'manufacturerName' => 'Other Business Manufacturer'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/manufacturers/{$manufacturer->id}", [
                'manufacturerName' => 'Hacked Manufacturer',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_can_delete_manufacturer()
    {
        $manufacturer = Manufacturer::factory()->create([
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/manufacturers/{$manufacturer->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', __(':noun deleted.', ['noun' => __('Manufacturer')]));

        $this->assertDatabaseMissing('manufacturers', [
            'id' => $manufacturer->id,
        ]);
    }

    public function test_cannot_delete_manufacturer_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $manufacturer = Manufacturer::factory()->create([
            'business_id' => $otherUser->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/manufacturers/{$manufacturer->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_manufacturer_name_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/manufacturers', [
                'manufacturerName' => '<script>alert("xss")</script>Samsung',
            ]);

        $response->assertStatus(200);

        $manufacturer = Manufacturer::where('business_id', $this->user->business_id)->first();
        $this->assertStringNotContainsString('<script>', $manufacturer->manufacturerName);
    }
}

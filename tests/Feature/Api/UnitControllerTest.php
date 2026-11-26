<?php

namespace Tests\Feature\Api;

use App\Models\Unit;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class UnitControllerTest extends TestCase
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

    public function test_can_list_units()
    {
        Unit::factory()->count(3)->create(['business_id' => $this->user->business_id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/units');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'unitName', 'business_id']
                ]
            ])
            ->assertJsonPath('message', __('Data fetched successfully.'));
    }

    public function test_can_create_unit()
    {
        $unitData = [
            'unitName' => 'Kilogram',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/units', $unitData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'unitName', 'business_id']
            ])
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('units', [
            'unitName' => 'Kilogram',
            'business_id' => $this->user->business_id,
        ]);
    }

    public function test_cannot_create_duplicate_unit()
    {
        Unit::factory()->create([
            'unitName' => 'Kilogram',
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/units', [
                'unitName' => 'Kilogram',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['unitName']);
    }

    public function test_can_update_unit()
    {
        $unit = Unit::factory()->create([
            'business_id' => $this->user->business_id,
            'unitName' => 'Old Unit'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/units/{$unit->id}", [
                'unitName' => 'New Unit',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('units', [
            'id' => $unit->id,
            'unitName' => 'New Unit',
        ]);
    }

    public function test_cannot_update_unit_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $unit = Unit::factory()->create([
            'business_id' => $otherUser->business_id,
            'unitName' => 'Other Business Unit'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/units/{$unit->id}", [
                'unitName' => 'Hacked Unit',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_can_delete_unit()
    {
        $unit = Unit::factory()->create([
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/units/{$unit->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', __(':noun deleted.', ['noun' => __('Unit')]));

        $this->assertDatabaseMissing('units', [
            'id' => $unit->id,
        ]);
    }

    public function test_cannot_delete_unit_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $unit = Unit::factory()->create([
            'business_id' => $otherUser->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/units/{$unit->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_unit_name_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/units', [
                'unitName' => '<script>alert("xss")</script>Meter',
            ]);

        $response->assertStatus(200);

        $unit = Unit::where('business_id', $this->user->business_id)->first();
        $this->assertStringNotContainsString('<script>', $unit->unitName);
    }
}

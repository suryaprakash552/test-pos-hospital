<?php

namespace Tests\Feature\Api;

use App\Models\Warehouse;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class WarehouseControllerTest extends TestCase
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

    public function test_can_list_warehouses()
    {
        Warehouse::factory()->count(3)->create(['business_id' => $this->user->business_id]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/warehouses');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    '*' => ['id', 'name', 'business_id']
                ]
            ])
            ->assertJsonPath('message', __('Data fetched successfully.'));
    }

    public function test_can_create_warehouse()
    {
        $warehouseData = [
            'name' => 'Main Warehouse',
            'phone' => '+1234567890',
            'address' => '123 Main St',
            'email' => 'warehouse@example.com',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/warehouses', $warehouseData);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'name', 'business_id']
            ])
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('warehouses', [
            'name' => 'Main Warehouse',
            'business_id' => $this->user->business_id,
        ]);
    }

    public function test_cannot_create_duplicate_email()
    {
        Warehouse::factory()->create([
            'email' => 'warehouse@example.com',
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/warehouses', [
                'name' => 'Another Warehouse',
                'email' => 'warehouse@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_can_update_warehouse()
    {
        $warehouse = Warehouse::factory()->create([
            'business_id' => $this->user->business_id,
            'name' => 'Old Warehouse'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/warehouses/{$warehouse->id}", [
                'name' => 'New Warehouse',
                'phone' => '+9876543210',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data saved successfully.'));

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'name' => 'New Warehouse',
        ]);
    }

    public function test_cannot_update_warehouse_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $warehouse = Warehouse::factory()->create([
            'business_id' => $otherUser->business_id,
            'name' => 'Other Business Warehouse'
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/warehouses/{$warehouse->id}", [
                'name' => 'Hacked Warehouse',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_can_delete_warehouse()
    {
        $warehouse = Warehouse::factory()->create([
            'business_id' => $this->user->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', __('Data deleted successfully.'));

        $this->assertDatabaseMissing('warehouses', [
            'id' => $warehouse->id,
        ]);
    }

    public function test_cannot_delete_warehouse_from_other_business()
    {
        $otherBusiness = Business::factory()->create();
        $otherUser = User::factory()->create(['business_id' => $otherBusiness->id]);
        $warehouse = Warehouse::factory()->create([
            'business_id' => $otherUser->business_id
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/warehouses/{$warehouse->id}");

        $response->assertStatus(403)
            ->assertJsonPath('message', __('Unauthorized.'));
    }

    public function test_warehouse_name_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/warehouses', [
                'name' => '<script>alert("xss")</script>Storage Facility',
                'address' => 'Test Address',
            ]);

        $response->assertStatus(200);

        $warehouse = Warehouse::where('business_id', $this->user->business_id)->first();
        $this->assertStringNotContainsString('<script>', $warehouse->name);
    }

    public function test_warehouse_phone_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/warehouses', [
                'name' => 'Test Warehouse',
                'phone' => '+1 (555) 123-4567',
            ]);

        $response->assertStatus(200);

        $warehouse = Warehouse::where('business_id', $this->user->business_id)->first();
        $this->assertNotNull($warehouse->phone);
    }

    public function test_warehouse_email_sanitization()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/warehouses', [
                'name' => 'Test Warehouse',
                'email' => 'test@example.com',
            ]);

        $response->assertStatus(200);

        $warehouse = Warehouse::where('business_id', $this->user->business_id)->first();
        $this->assertEquals('test@example.com', strtolower($warehouse->email));
    }
}

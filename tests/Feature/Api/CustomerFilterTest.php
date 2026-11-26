<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_and_phone_filters_are_independent_and_tenant_scoped(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Rayachoty Patient',
            'phone' => '9876543210',
        ]);
        Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'Rayachoty Patient',
            'phone' => '9999999999',
        ]);
        Customer::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'name' => 'Rayachoty Patient',
            'phone' => '9876543210',
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/v1/customers?name=Rayachoty&phone=987654'
        )->assertOk();

        $this->assertSame(
            ['9876543210'],
            collect($response->json('data.data'))->pluck('phone')->all()
        );
    }
}

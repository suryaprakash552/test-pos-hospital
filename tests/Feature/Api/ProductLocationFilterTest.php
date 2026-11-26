<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_rack_and_shelf_selections_match_exact_locations(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        Product::factory()->create([
            'business_id' => $business->id,
            'productName' => 'Correct location',
            'rack' => 'Rack 1',
            'shelf' => 'Shelf 2',
        ]);
        Product::factory()->create([
            'business_id' => $business->id,
            'productName' => 'Wrong rack',
            'rack' => 'Rack 10',
            'shelf' => 'Shelf 2',
        ]);
        Product::factory()->create([
            'business_id' => $business->id,
            'productName' => 'Wrong shelf',
            'rack' => 'Rack 1',
            'shelf' => 'Shelf 20',
        ]);

        $response = $this->actingAs($user)->getJson(
            '/api/v1/products-by-location?rack='.urlencode('rack 1').'&shelf='.urlencode('SHELF 2')
        )->assertOk();

        $this->assertSame(
            ['Correct location'],
            collect($response->json('data.data'))->pluck('productName')->all()
        );
    }
}

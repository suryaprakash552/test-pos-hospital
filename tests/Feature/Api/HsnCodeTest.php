<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * HSN / SAC classification and cess on a product.
 *
 * A GST return is filed HSN-wise, so a product without one cannot appear correctly on
 * it. The code is validated to the shape Indian GST uses — anything else would be
 * rejected at filing time, which is far later and much harder to trace back.
 */
class HsnCodeTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    private function createProduct(array $payload)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/products', $payload + [
            'productName'    => 'Paracetamol',
            'productPicture' => UploadedFile::fake()->image('p.jpg'),
        ]);
    }

    public function test_a_product_can_carry_an_hsn_code(): void
    {
        $this->createProduct(['hsn_code' => '30049099'])->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'productName' => 'Paracetamol',
            'hsn_code'    => '30049099',
        ]);
    }

    public function test_a_four_digit_code_is_accepted(): void
    {
        $this->createProduct(['hsn_code' => '3004'])->assertStatus(200);
    }

    public function test_a_malformed_code_is_rejected(): void
    {
        foreach (['abc', '300', '123456789', '3004-99'] as $bad) {
            $this->createProduct(['hsn_code' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors('hsn_code');
        }
    }

    public function test_a_product_without_a_code_is_still_valid(): void
    {
        // Not every shop files HSN-wise, and requiring it would block product entry.
        $this->createProduct([])->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'productName' => 'Paracetamol',
            'hsn_code'    => null,
        ]);
    }

    public function test_cess_is_stored(): void
    {
        $this->createProduct(['cess_rate' => 12.5])->assertStatus(200);

        $this->assertEquals(
            12.5,
            Product::where('productName', 'Paracetamol')->value('cess_rate'),
        );
    }

    public function test_an_impossible_cess_rate_is_rejected(): void
    {
        $this->createProduct(['cess_rate' => 150])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cess_rate');

        $this->createProduct(['cess_rate' => -5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cess_rate');
    }

    /**
     * Editing an unrelated field must not silently clear a product's tax
     * classification — that would quietly break its next return.
     */
    public function test_an_update_that_omits_the_code_keeps_it(): void
    {
        $product = Product::create([
            'productName' => 'Paracetamol',
            'business_id' => $this->business->id,
            'hsn_code'    => '30049099',
            'cess_rate'   => 12,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", [
                'productName' => 'Paracetamol 500mg',
            ])
            ->assertStatus(200);

        $fresh = $product->fresh();

        $this->assertSame('Paracetamol 500mg', $fresh->productName);
        $this->assertSame('30049099', $fresh->hsn_code);
        $this->assertEquals(12, $fresh->cess_rate);
    }

    public function test_the_code_can_be_changed(): void
    {
        $product = Product::create([
            'productName' => 'Paracetamol',
            'business_id' => $this->business->id,
            'hsn_code'    => '30049099',
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/products/{$product->id}", [
                // productName is required on update; this test is about the code.
                'productName' => 'Paracetamol',
                'hsn_code'    => '30041000',
            ])
            ->assertStatus(200);

        $this->assertSame('30041000', $product->fresh()->hsn_code);
    }
}

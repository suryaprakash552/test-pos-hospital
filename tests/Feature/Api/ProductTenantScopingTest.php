<?php

namespace Tests\Feature\Api;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A product may only reference its own shop's lookups.
 *
 * `StoreProductRequest` scopes every lookup with the `TenantOwned` rule.
 * `AcnooProductController::update` had drifted to bare `exists:categories,id`
 * and friends — a **global** check — while the row is written straight from the
 * request. So an edit could file this shop's product under another shop's
 * category, brand or unit.
 *
 * `vat_id` is the one that hurts: a foreign VAT applies *that* shop's tax rate
 * to this shop's sales. It is a financial error, not only a data leak. A bare
 * `exists` is also an enumeration oracle — a real id from another tenant
 * validates while an invented one does not.
 */
class ProductTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private User $owner;
    private Business $otherBusiness;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
        $this->otherBusiness = Business::factory()->create();

        $this->product = Product::create([
            'business_id'  => $this->business->id,
            'productName'  => 'Widget',
            'productCode'  => 'W-1',
            'productStock' => 0,
        ]);

        $this->actingAs($this->owner);
    }

    private function update(array $overrides)
    {
        return $this->putJson("/api/v1/products/{$this->product->id}", array_merge([
            'productName' => 'Widget',
        ], $overrides));
    }

    public function test_unchanged_legacy_references_do_not_block_metadata_edits(): void
    {
        $legacyBrand = Brand::factory()->create(['business_id' => $this->otherBusiness->id]);
        $this->product->update(['brand_id' => $legacyBrand->id, 'productUpperUnit' => 0]);
        $this->update(['brand_id' => $legacyBrand->id, 'upperSelectedUnit' => 0, 'rack' => 'A'])
            ->assertOk();
        $this->assertSame('A', $this->product->fresh()->rack);
        $this->assertSame(0, (int) $this->product->fresh()->productUpperUnit);
        $this->assertSame($legacyBrand->id, (int) $this->product->fresh()->brand_id);
    }

    public function test_alphanumeric_barcode_lookup_is_exact_and_tenant_scoped(): void
    {
        Product::create([
            'business_id' => $this->otherBusiness->id,
            'productName' => 'Other tenant item', 'productCode' => 'W-1',
        ]);
        $response = $this->getJson('/api/v1/products?barcode=W-1&per_page=10')->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->product->id, $rows[0]['id']);
        $this->getJson('/api/v1/products?barcode=W&per_page=10')
            ->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_a_foreign_category_is_refused(): void
    {
        $foreign = Category::factory()->create([
            'business_id' => $this->otherBusiness->id,
        ]);

        $this->update(['category_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $this->assertDatabaseMissing('products', [
            'id'          => $this->product->id,
            'category_id' => $foreign->id,
        ]);
    }

    public function test_a_foreign_vat_is_refused(): void
    {
        // The financial one: another shop's rate applied to this shop's sales.
        $foreign = Vat::create([
            'business_id' => $this->otherBusiness->id,
            'name'        => 'GST 28',
            'rate'        => 28,
        ]);

        $this->update(['vat_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('vat_id');

        $this->assertDatabaseMissing('products', [
            'id'     => $this->product->id,
            'vat_id' => $foreign->id,
        ]);
    }

    public function test_a_foreign_brand_is_refused(): void
    {
        $foreign = Brand::factory()->create([
            'business_id' => $this->otherBusiness->id,
        ]);

        $this->update(['brand_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('brand_id');
    }

    public function test_a_foreign_unit_is_refused(): void
    {
        $foreign = Unit::create([
            'business_id' => $this->otherBusiness->id,
            'unitName'    => 'Crate',
        ]);

        $this->update(['unit_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('unit_id');
    }

    public function test_the_shops_own_lookups_are_still_accepted(): void
    {
        // The scoping must not refuse legitimate edits.
        $category = Category::factory()->create([
            'business_id' => $this->business->id,
        ]);
        $vat = Vat::create([
            'business_id' => $this->business->id,
            'name'        => 'GST 18',
            'rate'        => 18,
        ]);

        $this->update([
            'category_id' => $category->id,
            'vat_id'      => $vat->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('products', [
            'id'          => $this->product->id,
            'category_id' => $category->id,
            'vat_id'      => $vat->id,
        ]);
    }

    public function test_another_business_cannot_edit_this_product_at_all(): void
    {
        $intruder = User::factory()->create([
            'business_id' => $this->otherBusiness->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($intruder)
            ->putJson("/api/v1/products/{$this->product->id}", [
                'productName' => 'Hijacked',
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('products', [
            'id'          => $this->product->id,
            'productName' => 'Widget',
        ]);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\BusinessCategory;
use App\Models\User;
use App\Services\DefaultVatType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Which tax mode a new product starts in.
 *
 * The convention follows the trade: a pharmacy prices inclusive of tax because the
 * shelf price is what the customer pays; a wholesaler quotes exclusive and adds tax on
 * the invoice. Every product used to default to 'exclusive' regardless, so half the
 * shops had to correct each product by hand.
 *
 * The behaviour for a shop that has set nothing must be unchanged — that is what keeps
 * this safe to deploy to existing businesses.
 */
class DefaultVatTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Keeps uploaded product images out of the real storage disk.
        Storage::fake(config('filesystems.default'));
    }

    private function businessWith(?string $shopTypeDefault, ?string $ownDefault = null): Business
    {
        $category = BusinessCategory::factory()->create([
            'default_vat_type' => $shopTypeDefault,
        ]);

        return Business::factory()->create([
            'business_category_id' => $category->id,
            'default_vat_type'     => $ownDefault,
        ]);
    }

    public function test_a_shop_that_has_set_nothing_behaves_as_before(): void
    {
        $business = $this->businessWith(null);

        $this->assertSame(
            DefaultVatType::EXCLUSIVE,
            DefaultVatType::forBusiness($business),
            'existing shops must not change behaviour'
        );
    }

    public function test_it_inherits_the_shop_types_convention(): void
    {
        $business = $this->businessWith(DefaultVatType::INCLUSIVE);

        $this->assertSame(
            DefaultVatType::INCLUSIVE,
            DefaultVatType::forBusiness($business)
        );
    }

    public function test_the_business_can_override_its_shop_type(): void
    {
        $business = $this->businessWith(
            DefaultVatType::INCLUSIVE,
            DefaultVatType::EXCLUSIVE,
        );

        $this->assertSame(
            DefaultVatType::EXCLUSIVE,
            DefaultVatType::forBusiness($business),
            'the shop owner has the final say'
        );
    }

    public function test_a_nonsense_stored_value_falls_back_rather_than_propagating(): void
    {
        $business = $this->businessWith('sometimes');

        $this->assertSame(DefaultVatType::EXCLUSIVE, DefaultVatType::forBusiness($business));
    }

    public function test_a_business_with_no_shop_type_falls_back(): void
    {
        $this->assertSame(DefaultVatType::EXCLUSIVE, DefaultVatType::forBusiness(null));
    }

    public function test_an_explicit_choice_always_wins(): void
    {
        $business = $this->businessWith(DefaultVatType::INCLUSIVE);

        $this->assertSame(
            DefaultVatType::EXCLUSIVE,
            DefaultVatType::resolve(DefaultVatType::EXCLUSIVE, $business),
            'what the user picked on the form must not be overridden'
        );
    }

    public function test_an_absent_choice_uses_the_default(): void
    {
        $business = $this->businessWith(DefaultVatType::INCLUSIVE);

        $this->assertSame(
            DefaultVatType::INCLUSIVE,
            DefaultVatType::resolve(null, $business)
        );
    }

    // ── End to end ───────────────────────────────────────────────────────────

    public function test_a_product_created_without_a_tax_mode_takes_the_shop_default(): void
    {
        $business = $this->businessWith(DefaultVatType::INCLUSIVE);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/products', [
                'productName'    => 'Paracetamol',
                // Required by StoreProductRequest; irrelevant to the tax mode.
                'productPicture' => UploadedFile::fake()->image('p.jpg'),
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'productName' => 'Paracetamol',
            'vat_type'    => DefaultVatType::INCLUSIVE,
        ]);
    }

    public function test_a_product_keeps_the_tax_mode_the_user_chose(): void
    {
        $business = $this->businessWith(DefaultVatType::INCLUSIVE);
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/products', [
                'productName'    => 'Bulk sacks',
                'vat_type'       => DefaultVatType::EXCLUSIVE,
                'productPicture' => UploadedFile::fake()->image('p.jpg'),
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('products', [
            'productName' => 'Bulk sacks',
            'vat_type'    => DefaultVatType::EXCLUSIVE,
        ]);
    }
}

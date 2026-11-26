<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Option;
use App\Models\PaymentType;
use App\Models\ProductModel;
use App\Models\ProductSetting;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsControllersTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;
    private Business $otherBusiness;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->otherBusiness = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);
    }

    public function test_product_settings_store_only_allows_known_validated_keys(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/product-settings', [
            'show_product_price' => 0,
            'default_batch_no' => 'BATCH-A',
            'business_id' => $this->otherBusiness->id,
            'danger' => 'bad',
        ]);

        $response->assertOk();

        $setting = ProductSetting::where('business_id', $this->business->id)->firstOrFail();
        // Toggles are normalised to the string form on write — the same one
        // `ProductSettingDefaults` emits and the till reads. The column used to
        // keep whatever type reached `validated()` (an int here, a string from
        // a multipart post), so it held both and the till threw on the int.
        $this->assertSame('0', $setting->modules['show_product_price']);
        // A stored value, not a toggle: kept exactly as sent.
        $this->assertSame('BATCH-A', $setting->modules['default_batch_no']);
        $this->assertArrayNotHasKey('danger', $setting->modules);
        $this->assertDatabaseMissing('product_settings', [
            'business_id' => $this->otherBusiness->id,
        ]);
    }

    public function test_product_settings_rejects_unknown_boolean_values(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/product-settings', [
            'show_product_price' => 5,
        ]);

        $response->assertStatus(422);
    }

    public function test_payment_type_update_and_delete_are_business_scoped(): void
    {
        $paymentType = PaymentType::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Other UPI',
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/payment-types/{$paymentType->id}", ['name' => 'Blocked'])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/payment-types/{$paymentType->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('payment_types', [
            'id' => $paymentType->id,
            'name' => 'Other UPI',
        ]);
    }

    public function test_product_model_update_and_delete_are_business_scoped(): void
    {
        $productModel = ProductModel::create([
            'business_id' => $this->otherBusiness->id,
            'name' => 'Other Model',
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/product-models/{$productModel->id}", ['name' => 'Blocked'])
            ->assertNotFound();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/product-models/{$productModel->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('product_models', [
            'id' => $productModel->id,
            'name' => 'Other Model',
        ]);
    }

    public function test_invoice_setting_update_and_index_use_same_business_key(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoice-settings/update', ['invoice_size' => 'a4'])
            ->assertOk();

        $this->assertDatabaseHas('options', [
            'key' => 'invoice_setting_' . $this->business->id,
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/invoice-settings')
            ->assertOk()
            ->assertJsonPath('invoice_size', 'a4');
    }

    public function test_invoice_setting_index_supports_legacy_json_record(): void
    {
        Option::create([
            'key' => 'invoice_settings',
            'value' => [
                'business_id' => $this->business->id,
                'invoice_size' => '3_inch_80mm',
            ],
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/invoice-settings')
            ->assertOk()
            ->assertJsonPath('invoice_size', '3_inch_80mm');
    }

    /**
     * The saved page size actually reaches PDF generation.
     *
     * `invoice_setting()` returns the whole stored option
     * (`['business_id' => .., 'invoice_size' => 'a4']`), not the size
     * alone. `/business` merged that whole array in under the `invoice_size`
     * key, so the app's `invoiceSize` field received the array's string
     * conversion instead of 'a4' - no size a shop picked ever actually
     * changed what a generated invoice's page width was.
     */
    public function test_the_business_endpoint_returns_the_invoice_size_string_not_the_option_array(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoice-settings/update', ['invoice_size' => 'a4'])
            ->assertOk();

        $this->actingAs($this->user)
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('data.invoice_size', 'a4');
    }

    public function test_a3_is_accepted_as_an_invoice_size(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/invoice-settings/update', ['invoice_size' => 'a3'])
            ->assertOk();
    }

    public function test_api_mutations_send_private_no_store_headers_without_cookies(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/product-settings', [
            'show_product_price' => 1,
        ]);

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control', ''));
        $this->assertCount(0, $response->headers->getCookies());
    }

    public function test_cache_service_builds_stable_tenant_scoped_keys(): void
    {
        $this->assertSame(
            "business:{$this->business->id}:product_settings",
            CacheService::businessKey('product settings', $this->business->id)
        );

        $this->assertNotSame(
            CacheService::businessKey('product_settings', $this->business->id),
            CacheService::businessKey('product_settings', $this->otherBusiness->id)
        );
    }
}

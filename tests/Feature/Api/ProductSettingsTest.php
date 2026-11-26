<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\ProductSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The product field-visibility drawer.
 *
 * Saving it always failed with "Please Try Again!". The app posted the toggle
 * '1'/'0' into `default_expired_date` and `default_mfg_date`, which are validated
 * as dates - `strtotime('1')` is false, so every save 422'd and nothing persisted.
 *
 * Two further faults sat behind that one, and would have kept the screen broken
 * once the 422 was gone: nine toggles the app reads back were missing from the
 * allowlist, so `validated()` silently dropped them; and the controller layered
 * the payload over the *defaults*, so any key the client did not send reverted
 * rather than being preserved.
 */
class ProductSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function save(array $payload)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/product-settings', $payload);
    }

    private function storedModules(): array
    {
        return ProductSetting::forBusiness((int) $this->user->business_id)->first()->modules;
    }

    /** @return array<string, array{string}> */
    public static function provideDroppedToggles(): array
    {
        $keys = [
            'show_product_description',
            'show_product_maximum_discount',
            'show_product_rack',
            'show_product_shelf',
            'show_conversion_value',
            'show_upper_selected_unit',
            'show_lower_selected_unit',
            'show_product_flat_discount',
            'show_product_percent_discount',
        ];

        return array_combine($keys, array_map(fn ($k) => [$k], $keys));
    }

    /** @dataProvider provideDroppedToggles */
    public function test_a_toggle_the_app_renders_can_actually_be_turned_on(string $key): void
    {
        $this->save([$key => 1])->assertSuccessful();

        // Stored as the string '1', which is what `ProductSettingDefaults`
        // emits and what the till reads. It used to keep whatever type reached
        // `validated()` — an int here, a string from a multipart post — so the
        // column held both, and the till, which declares every one of these
        // fields as a string, threw on the int.
        $this->assertSame('1', $this->storedModules()[$key], "{$key} was dropped by validation.");
    }

    public function test_a_toggle_can_be_turned_back_off(): void
    {
        $this->save(['show_product_rack' => 1])->assertSuccessful();
        $this->save(['show_product_rack' => 0])->assertSuccessful();

        $this->assertSame('0', $this->storedModules()['show_product_rack']);
    }

    public function test_a_key_the_client_did_not_send_keeps_its_stored_value(): void
    {
        // A value this screen does not render, so it is never in the payload.
        ProductSetting::create([
            'business_id' => $this->user->business_id,
            'modules' => ['default_dealer_price' => '250', 'show_warehouse' => '1'],
        ]);

        $this->save(['show_product_rack' => 1])->assertSuccessful();

        $modules = $this->storedModules();
        $this->assertSame('250', $modules['default_dealer_price']);
        $this->assertSame('1', $modules['show_warehouse']);
    }

    public function test_a_date_field_still_refuses_a_boolean_toggle(): void
    {
        // The app no longer posts these, but the rule is what protects the column
        // from the next caller that tries.
        $this->save(['default_expired_date' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('default_expired_date');
    }

    public function test_a_real_date_is_accepted(): void
    {
        $this->save(['default_expired_date' => '2026-12-31'])->assertSuccessful();

        $this->assertSame('2026-12-31', $this->storedModules()['default_expired_date']);
    }

    public function test_settings_are_scoped_to_the_business_that_saved_them(): void
    {
        $this->save(['show_product_rack' => 1])->assertSuccessful();

        $other = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);

        $this->actingAs($other)->getJson('/api/v1/product-settings')->assertSuccessful();

        $this->assertSame(
            1,
            ProductSetting::forBusiness((int) $this->user->business_id)->count(),
            'A second business must not share the first one\'s settings row.',
        );
    }
}

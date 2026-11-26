<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use App\Support\Api\ProductSettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving the Add Product field settings.
 *
 * Reported as "The show product maximum discount must be an integer", with none
 * of the chosen settings saved. The toggle for that field was commented out on
 * the client, so the model value stayed null and `value.toString()` posted the
 * literal string "null" as a multipart field. Every toggle is validated
 * `nullable|integer|in:0,1`, so that one unset field failed the whole request
 * and the other forty settings went down with it.
 *
 * The client now omits fields it has no value for. These tests hold the backend
 * end of that contract: the string "null" is not an acceptable toggle, an absent
 * key is, and what is sent comes back on the next read.
 */
class ProductSettingsSaveTest extends TestCase
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
        return $this->actingAs($this->user)
            ->postJson('/api/v1/product-settings', $payload);
    }

    private function readBack(): array
    {
        return $this->actingAs($this->user)
            ->getJson('/api/v1/product-settings')
            ->assertSuccessful()
            ->json();
    }

    public function test_a_toggle_can_be_turned_on_and_read_back(): void
    {
        $this->save(['show_product_maximum_discount' => 1])->assertSuccessful();

        $this->assertEquals(
            1,
            data_get($this->readBack(), 'data.modules.show_product_maximum_discount'),
        );
    }

    public function test_a_toggle_can_be_turned_off_and_read_back(): void
    {
        $this->save(['show_product_maximum_discount' => 1])->assertSuccessful();
        $this->save(['show_product_maximum_discount' => 0])->assertSuccessful();

        $this->assertEquals(
            0,
            data_get($this->readBack(), 'data.modules.show_product_maximum_discount'),
        );
    }

    /**
     * The exact payload that broke it.
     *
     * Kept as a refusal rather than made to pass: "null" is not a toggle value,
     * and accepting it would only move the ambiguity into storage.
     */
    public function test_the_string_null_is_refused_rather_than_stored(): void
    {
        $this->save(['show_product_maximum_discount' => 'null'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('show_product_maximum_discount');
    }

    public function test_a_boolean_is_refused(): void
    {
        $this->save(['show_product_maximum_discount' => 'true'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('show_product_maximum_discount');
    }

    /**
     * Omitting a field is how the client now handles "no value", so it has to
     * be accepted and must not disturb the settings already stored.
     */
    public function test_an_omitted_field_is_accepted_and_leaves_others_intact(): void
    {
        $this->save([
            'show_product_maximum_discount' => 1,
            'show_product_rack' => 1,
        ])->assertSuccessful();

        // Second save mentions only one of them.
        $this->save(['show_product_rack' => 0])->assertSuccessful();

        $modules = data_get($this->readBack(), 'data.modules');

        $this->assertEquals(0, $modules['show_product_rack']);
        $this->assertArrayHasKey('show_product_maximum_discount', $modules);
    }

    public function test_an_explicit_null_is_accepted_for_a_nullable_field(): void
    {
        $this->save(['default_batch_no' => null])->assertSuccessful();
    }

    public function test_a_value_outside_zero_and_one_is_refused(): void
    {
        $this->save(['show_product_rack' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors('show_product_rack');
    }

    /**
     * Every toggle the client can post is one the backend knows about.
     *
     * The reported field was missing from the defaults once before, which made
     * `validated()` drop it silently so it could never be turned on.
     */
    public function test_the_reported_toggles_are_all_known_keys(): void
    {
        $known = ProductSettingDefaults::allowedKeys();

        foreach ([
            'show_product_maximum_discount',
            'show_product_description',
            'show_product_rack',
            'show_product_shelf',
            'show_conversion_value',
        ] as $key) {
            $this->assertContains($key, $known, "$key is not an accepted setting.");
        }
    }
}

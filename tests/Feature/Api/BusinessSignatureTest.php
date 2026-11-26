<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The authorised signature printed on the sales invoice.
 *
 * Stored as a column on the business and uploaded once in settings, the same
 * way `upi_qr_image` is — one fixed image on every bill, not a per-sale capture.
 */
class BusinessSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $attributes = []): User
    {
        $business = Business::factory()->create($attributes + [
            'companyName' => 'Test Shop',
        ]);

        return User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
    }

    public function test_a_signature_can_be_uploaded_and_is_returned_by_the_business_endpoint(): void
    {
        Storage::fake(config('filesystems.default'));
        $user = $this->owner();

        $this->actingAs($user)
            ->putJson('/api/v1/business/' . $user->business_id, [
                'companyName' => 'Test Shop',
                'signature_image' => UploadedFile::fake()->image('sign.png'),
            ])
            ->assertSuccessful();

        $stored = Business::find($user->business_id)->signature_image;
        $this->assertNotNull($stored);
        Storage::disk(config('filesystems.default'))->assertExists($stored);

        $this->actingAs($user)
            ->getJson('/api/v1/business')
            ->assertOk()
            ->assertJsonPath('data.signature_image', $stored);
    }

    public function test_a_non_image_signature_is_rejected(): void
    {
        $user = $this->owner();

        $this->actingAs($user)
            ->putJson('/api/v1/business/' . $user->business_id, [
                'companyName' => 'Test Shop',
                'signature_image' => UploadedFile::fake()->create('sign.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signature_image');
    }

    public function test_saving_an_unrelated_field_keeps_the_stored_signature(): void
    {
        $user = $this->owner(['signature_image' => 'uploads/existing-sign.png']);

        $this->actingAs($user)
            ->putJson('/api/v1/business/' . $user->business_id, [
                'companyName' => 'Test Shop',
                'address' => 'New address, same signature',
            ])
            ->assertSuccessful();

        $this->assertSame(
            'uploads/existing-sign.png',
            Business::find($user->business_id)->signature_image,
        );
    }
}

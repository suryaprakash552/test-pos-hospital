<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which QR the till shows (§11).
 *
 * The server used to decide: an uploaded image if one existed, a rendered one
 * otherwise. That is a reasonable default and a poor rule - a shop that uploads a
 * QR for one counter cannot go back to dynamic codes without deleting it, and the
 * two are not interchangeable at the till because only a dynamic code carries the
 * amount.
 *
 * `auto` is exactly the old behaviour, so a shop that has never chosen sees no
 * change.
 */
class UpiQrModeTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $mode, ?string $qrImage): User
    {
        $business = Business::factory()->create([
            'upi_id' => 'shop@upi',
            'companyName' => 'Test Shop',
            'upi_qr_image' => $qrImage,
            'upi_qr_mode' => $mode,
        ]);

        return User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);
    }

    private function qr(User $user)
    {
        return $this->actingAs($user)
            ->postJson('/api/v1/generate-upi-qr', ['amount' => 250]);
    }

    public function test_auto_prefers_an_uploaded_qr(): void
    {
        $this->qr($this->shop('auto', 'uploads/shop-qr.png'))
            ->assertSuccessful()
            ->assertJsonPath('qr_source', 'uploaded');
    }

    public function test_auto_renders_one_when_nothing_is_uploaded(): void
    {
        $this->qr($this->shop('auto', null))
            ->assertSuccessful()
            ->assertJsonPath('qr_source', fn ($v) => $v !== 'uploaded');
    }

    public function test_uploaded_mode_uses_the_uploaded_image(): void
    {
        $this->qr($this->shop('uploaded', 'uploads/shop-qr.png'))
            ->assertSuccessful()
            ->assertJsonPath('qr_source', 'uploaded');
    }

    /**
     * The case the mode exists for: an uploaded image is present and ignored.
     */
    public function test_dynamic_mode_ignores_an_uploaded_image(): void
    {
        $this->qr($this->shop('dynamic', 'uploads/shop-qr.png'))
            ->assertSuccessful()
            ->assertJsonPath('qr_source', fn ($v) => $v !== 'uploaded');
    }

    /**
     * No silent fallback.
     *
     * A shop that asked for its own QR and quietly got a different one has no way
     * to notice, and the money goes wherever the substitute encodes.
     */
    public function test_uploaded_mode_with_no_image_says_so_rather_than_substituting(): void
    {
        $this->qr($this->shop('uploaded', null))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'qr_image_missing');
    }

    public function test_the_amount_is_returned_whichever_mode_is_used(): void
    {
        // A static QR cannot carry the amount, so the till has to display it.
        $this->qr($this->shop('uploaded', 'uploads/shop-qr.png'))
            ->assertSuccessful()
            ->assertJsonPath('amount', fn ($v) => (float) $v === 250.0);
    }

    public function test_a_shop_with_no_stored_mode_behaves_as_before(): void
    {
        $business = Business::factory()->create([
            'upi_id' => 'shop@upi',
            'companyName' => 'Legacy Shop',
            'upi_qr_image' => 'uploads/legacy-qr.png',
        ]);

        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        $this->qr($user)
            ->assertSuccessful()
            ->assertJsonPath('qr_source', 'uploaded');
    }

    public function test_an_unknown_mode_is_refused_on_save(): void
    {
        $user = $this->shop('auto', null);

        $this->actingAs($user)
            ->putJson('/api/v1/business/' . $user->business_id, [
                'companyName' => 'Test Shop',
                'upi_qr_mode' => 'whatever',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upi_qr_mode');
    }

    public function test_the_mode_can_be_saved_and_read_back(): void
    {
        $user = $this->shop('auto', 'uploads/shop-qr.png');

        $this->actingAs($user)
            ->putJson('/api/v1/business/' . $user->business_id, [
                'companyName' => 'Test Shop',
                'upi_qr_mode' => 'dynamic',
            ])
            ->assertSuccessful();

        $this->assertSame('dynamic', Business::find($user->business_id)->upi_qr_mode);

        // And it takes effect immediately, without another round trip.
        $this->qr($user->fresh())
            ->assertSuccessful()
            ->assertJsonPath('qr_source', fn ($v) => $v !== 'uploaded');
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * UPI payments recorded only an amount and the sale's own date. The customer's app
 * shows a UTR / reference and a timestamp, and the bank statement is keyed on them —
 * with neither stored, a disputed or missing payment could not be matched to a sale,
 * which is what made reconciliation after a UPI payment unreliable.
 */
class UpiReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create(['upi_id' => 'shop@bank']);
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    public function test_the_reference_columns_exist_on_every_payment_table(): void
    {
        foreach (['sales', 'purchases', 'customer_due_collects'] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'upi_reference'),
                "$table must be able to record a UPI reference"
            );
            $this->assertTrue(
                Schema::hasColumn($table, 'upi_paid_at'),
                "$table must be able to record when the payment cleared"
            );
        }
    }

    public function test_a_reference_and_payment_time_are_stored_on_a_sale(): void
    {
        $paidAt = now()->subMinutes(5);

        DB::table('sales')->insert([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'S-1',
            'totalAmount'   => 500,
            'upi_amount'    => 500,
            'paymentType'   => 'upi',
            'upi_reference' => '431234567890',
            'upi_paid_at'   => $paidAt,
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $sale = DB::table('sales')->where('invoiceNumber', 'S-1')->first();

        $this->assertSame('431234567890', $sale->upi_reference);
        $this->assertNotNull($sale->upi_paid_at);
    }

    /**
     * A sale entered offline and synced later has two different times; the payment
     * time is the one the bank statement will show.
     */
    public function test_the_payment_time_is_independent_of_the_sale_date(): void
    {
        DB::table('sales')->insert([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'S-2',
            'totalAmount'   => 100,
            'upi_reference' => 'REF-1',
            'upi_paid_at'   => '2026-07-30 18:45:00',
            'saleDate'      => '2026-07-31',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $sale = DB::table('sales')->where('invoiceNumber', 'S-2')->first();

        $this->assertStringStartsWith('2026-07-30', $sale->upi_paid_at);
        $this->assertStringStartsWith('2026-07-31', $sale->saleDate);
    }

    public function test_cash_sales_simply_leave_the_reference_empty(): void
    {
        DB::table('sales')->insert([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'S-3',
            'totalAmount'   => 100,
            'paymentType'   => 'cash',
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $sale = DB::table('sales')->where('invoiceNumber', 'S-3')->first();

        $this->assertNull($sale->upi_reference);
        $this->assertNull($sale->upi_paid_at);
    }

    public function test_the_confirmation_image_column_exists(): void
    {
        foreach (['sales', 'purchases', 'customer_due_collects'] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'upi_confirmation_image'),
                "$table must be able to keep a payment confirmation"
            );
        }
    }

    /**
     * Evidence, never a requirement: a queue must not be held up because a customer
     * will not hand over their phone.
     */
    public function test_a_payment_without_a_confirmation_image_is_fine(): void
    {
        DB::table('sales')->insert([
            'business_id'   => $this->business->id,
            'invoiceNumber' => 'S-noimg',
            'totalAmount'   => 100,
            'upi_reference' => 'REF-2',
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $sale = DB::table('sales')->where('invoiceNumber', 'S-noimg')->first();

        $this->assertNull($sale->upi_confirmation_image);
        $this->assertSame('REF-2', $sale->upi_reference);
    }

    public function test_a_non_image_confirmation_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'upi_confirmation_image' => \Illuminate\Http\UploadedFile::fake()
                    ->create('notes.pdf', 20),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upi_confirmation_image');
    }

    public function test_an_overlong_reference_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'upi_reference' => str_repeat('X', 101),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upi_reference');
    }

    public function test_a_non_date_payment_time_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/sales', [
                'upi_paid_at' => 'yesterday afternoon',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('upi_paid_at');
    }

    // ── QR ───────────────────────────────────────────────────────────────────

    public function test_an_uploaded_qr_is_served_instead_of_calling_a_third_party(): void
    {
        $this->business->update(['upi_qr_image' => 'uploads/26/07/shop-qr.png']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/generate-upi-qr', ['amount' => 250])
            ->assertStatus(200)
            ->assertJsonPath('qr_source', 'uploaded')
            ->assertJsonPath('qr_image_url', 'uploads/26/07/shop-qr.png')
            // A static QR encodes the payee but not the amount, so the client must
            // still show the amount to the customer.
            ->assertJsonPath('amount_encoded', false);
    }

    public function test_the_payment_link_is_returned_either_way(): void
    {
        $this->business->update(['upi_qr_image' => 'uploads/26/07/shop-qr.png']);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/generate-upi-qr', ['amount' => 250])
            ->assertStatus(200);

        // Any UPI app can open this directly, so the till can still take payment
        // even when no image is available.
        $this->assertStringContainsString('upi://pay', $response->json('upi_url'));
        $this->assertStringContainsString('shop@bank', $response->json('upi_url'));
    }

    public function test_a_business_without_a_upi_id_is_told_so(): void
    {
        $this->business->update(['upi_id' => null]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/generate-upi-qr', ['amount' => 100])
            ->assertStatus(400);
    }
}

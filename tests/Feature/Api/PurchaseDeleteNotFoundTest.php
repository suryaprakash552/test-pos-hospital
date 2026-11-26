<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Deleting a purchase that is not there is a 404, not a server error.
 *
 * `destroy()` takes a raw `string $id` — no route-model binding — and looked
 * the purchase up with `firstOrFail()` inside a try whose catch answers 500 and
 * writes the exception with a full stack trace at error level.
 *
 * So an already-deleted purchase, a stale ID from a retry, or an ID belonging
 * to another business all came back as 500 and all looked, in the log, exactly
 * like the application falling over. It was the loudest recurring entry in the
 * production log and it buried the entries that mattered.
 *
 * The sales equivalent never had this: it binds `Sale $sale`, so a missing sale
 * 404s before the method body, and it checks the tenant explicitly with a 403.
 *
 * The lock-and-refetch `firstOrFail()` calls further down are left alone on
 * purpose — those fire only if a row disappears mid-transaction, which really
 * is a server-side fault and really should be a logged 500.
 */
class PurchaseDeleteNotFoundTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);
    }

    public function test_an_unknown_id_is_a_404(): void
    {
        $this->actingAs($this->owner())
            ->deleteJson('/api/v1/purchase/987654')
            ->assertStatus(404);
    }

    public function test_another_businesss_purchase_is_a_404(): void
    {
        // Not found rather than forbidden: confirming the row exists would
        // leak that another business has a purchase under that ID.
        $stranger = $this->owner();
        $theirs = DB::table('purchases')->insertGetId([
            'business_id' => $stranger->business_id,
            'user_id' => $stranger->id,
            'party_id' => DB::table('parties')->insertGetId([
                'business_id' => $stranger->business_id,
                'name' => 'Their Supplier',
                'type' => 'supplier',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'totalAmount' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->owner())
            ->deleteJson("/api/v1/purchase/{$theirs}")
            ->assertStatus(404);

        $this->assertDatabaseHas('purchases', ['id' => $theirs]);
    }

    public function test_a_missing_purchase_is_not_logged_as_an_error(): void
    {
        // The point of the fix: this used to write a stack trace per attempt.
        Log::spy();

        $this->actingAs($this->owner())
            ->deleteJson('/api/v1/purchase/987654')
            ->assertStatus(404);

        Log::shouldNotHaveReceived('error');
    }
}

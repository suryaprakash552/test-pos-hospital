<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Transaction history was reachable by cascade from records people delete.
 *
 * `sales.user_id`, `purchases.user_id`, `expenses.user_id` and `incomes.user_id`
 * were `cascadeOnDelete` and `AcnooUserController::destroy` had no guard at all,
 * so removing a member of staff who had left deleted every sale they had ever
 * rung up. `PartyController::destroy` checked only for an outstanding balance,
 * which a customer who always paid on time will always satisfy.
 *
 * Each test asserts the history survives. The status code and wording are how
 * the app explains the refusal; the rows staying put is the point.
 */
class HistoryDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
        $this->owner = User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'owner',
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create([
            'business_id' => $this->business->id,
            'role' => 'staff',
        ]);
    }

    private function party(array $attributes = []): int
    {
        return DB::table('parties')->insertGetId(array_merge([
            'business_id' => $this->business->id,
            'type' => 'Retailer',
            'due' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function sale(array $attributes = []): int
    {
        return DB::table('sales')->insertGetId(array_merge([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'totalAmount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_a_user_with_sales_is_not_deleted(): void
    {
        $staff = $this->staff();
        $saleId = $this->sale(['user_id' => $staff->id]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/v1/users/{$staff->id}");

        $response->assertStatus(409);

        // The revenue record is the thing being protected.
        $this->assertDatabaseHas('sales', ['id' => $saleId]);
        $this->assertDatabaseHas('users', ['id' => $staff->id]);
    }

    public function test_the_refusal_names_what_is_in_the_way(): void
    {
        $staff = $this->staff();
        $this->sale(['user_id' => $staff->id]);
        $this->sale(['user_id' => $staff->id]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/v1/users/{$staff->id}");

        // "Could not delete" leaves an owner with nothing to do next; the count
        // tells them what they are actually looking at.
        $this->assertStringContainsString('2 sale(s)', $response->json('message'));
    }

    public function test_a_user_with_expenses_is_not_deleted(): void
    {
        $staff = $this->staff();
        $expenseId = DB::table('expenses')->insertGetId([
            'business_id' => $this->business->id,
            'user_id' => $staff->id,
            'amount' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/users/{$staff->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('expenses', ['id' => $expenseId]);
    }

    public function test_a_user_with_no_history_is_still_deleted(): void
    {
        $staff = $this->staff();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/users/{$staff->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $staff->id]);
    }

    public function test_a_settled_party_with_purchases_is_not_deleted(): void
    {
        // due = 0, so the balance check this endpoint already had passes. That
        // check was the only thing standing between a paid-up supplier and their
        // entire trading history.
        //
        // Purchases, not sales: sales.party_id was renamed to customer_id in
        // 2026_03_24_071053 and points at `customers`. Parties are the supplier
        // side now, so purchases are where a party's history lives.
        $partyId = $this->party(['due' => 0]);
        $purchaseId = DB::table('purchases')->insertGetId([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'party_id' => $partyId,
            'totalAmount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/parties/{$partyId}")
            ->assertStatus(409);

        $this->assertDatabaseHas('purchases', ['id' => $purchaseId]);
        $this->assertDatabaseHas('parties', ['id' => $partyId]);
    }

    public function test_a_party_with_no_history_is_still_deleted(): void
    {
        $partyId = $this->party();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/parties/{$partyId}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('parties', ['id' => $partyId]);
    }

    public function test_a_party_with_an_outstanding_balance_is_still_refused(): void
    {
        // The pre-existing guard has to keep working; it catches a case the
        // history check does not (money owed, nothing recorded against it yet).
        $partyId = $this->party(['due' => 250]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/parties/{$partyId}")
            ->assertStatus(400);
    }

    public function test_a_vat_rate_in_use_by_products_is_not_deleted(): void
    {
        $vat = Vat::factory()->create(['business_id' => $this->business->id]);
        $product = Product::factory()->create([
            'business_id' => $this->business->id,
            'category_id' => Category::factory()->create([
                'business_id' => $this->business->id,
            ])->id,
            'vat_id' => $vat->id,
        ]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/vats/{$vat->id}")
            ->assertStatus(409);

        // sales.vat_id and purchases.vat_id were nullOnDelete in the same
        // migration block; only products cascaded.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_an_unused_vat_rate_still_deletes(): void
    {
        $vat = Vat::factory()->create(['business_id' => $this->business->id]);

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/vats/{$vat->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('vats', ['id' => $vat->id]);
    }
}

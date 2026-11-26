<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerTier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Customer tiers are a label printed on the bill — "Wholesale", "Staff", "Gold".
 *
 * The rule that matters: a tier carries **no** pricing. Discounts stay a manual
 * decision at the till, so renaming, deactivating or deleting a tier must never change
 * what a customer is charged.
 */
class CustomerTierTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::factory()->create();
        $this->user = User::factory()->create([
            'business_id' => $this->business->id,
            'role'        => 'shop-owner',
        ]);
    }

    private function tier(array $attributes = []): CustomerTier
    {
        return CustomerTier::create($attributes + [
            'business_id' => $this->business->id,
            'name'        => 'Wholesale',
        ]);
    }

    public function test_a_tier_can_be_created(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-tiers', [
                'name'        => 'Gold',
                'description' => 'Long-standing customers',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Gold');

        $this->assertDatabaseHas('customer_tiers', [
            'name'        => 'Gold',
            'business_id' => $this->business->id,
        ]);
    }

    public function test_a_new_tier_is_active_by_default(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-tiers', ['name' => 'Gold'])
            ->assertJsonPath('data.status', true);
    }

    public function test_tier_names_are_unique_within_a_business(): void
    {
        $this->tier(['name' => 'Gold']);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customer-tiers', ['name' => 'Gold'])
            ->assertStatus(422);
    }

    public function test_two_businesses_may_both_have_a_gold_tier(): void
    {
        $this->tier(['name' => 'Gold']);

        $otherBusiness = Business::factory()->create();
        $otherOwner = User::factory()->create([
            'business_id' => $otherBusiness->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($otherOwner)
            ->postJson('/api/v1/customer-tiers', ['name' => 'Gold'])
            ->assertStatus(200);
    }

    public function test_the_list_is_scoped_to_the_business(): void
    {
        $this->tier(['name' => 'Gold']);

        $otherBusiness = Business::factory()->create();
        CustomerTier::create([
            'business_id' => $otherBusiness->id,
            'name'        => 'Someone elses tier',
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/customer-tiers')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Gold');
    }

    public function test_the_list_is_ordered_by_sort_order(): void
    {
        $this->tier(['name' => 'Retail', 'sort_order' => 2]);
        $this->tier(['name' => 'Wholesale', 'sort_order' => 1]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/customer-tiers')
            ->assertJsonPath('data.0.name', 'Wholesale')
            ->assertJsonPath('data.1.name', 'Retail');
    }

    public function test_another_business_cannot_edit_a_tier(): void
    {
        $tier = $this->tier();

        $outsider = User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role'        => 'shop-owner',
        ]);

        $this->actingAs($outsider)
            ->putJson("/api/v1/customer-tiers/{$tier->id}", ['name' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('Wholesale', $tier->fresh()->name);
    }

    // ── The rule that protects pricing ───────────────────────────────────────

    public function test_a_customer_can_be_assigned_a_tier(): void
    {
        $tier = $this->tier();

        $customer = Customer::create([
            'name'             => 'Acme Ltd',
            'business_id'      => $this->business->id,
            'customer_tier_id' => $tier->id,
        ]);

        $this->assertSame($tier->id, $customer->fresh()->tier->id);
    }

    /**
     * Deleting a tier must not take customers with it.
     */
    public function test_deleting_a_tier_detaches_it_rather_than_removing_customers(): void
    {
        $tier = $this->tier();

        $customer = Customer::create([
            'name'             => 'Acme Ltd',
            'business_id'      => $this->business->id,
            'customer_tier_id' => $tier->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/customer-tiers/{$tier->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        $this->assertNull($customer->fresh()->customer_tier_id);
    }

    /**
     * The whole point: a tier is a label, never a price rule.
     */
    public function test_a_tier_carries_no_pricing_columns(): void
    {
        $columns = array_map(
            fn ($column) => strtolower($column),
            \Schema::getColumnListing('customer_tiers')
        );

        foreach (['discount', 'price', 'rate', 'percent', 'amount'] as $forbidden) {
            $matches = array_filter(
                $columns,
                fn ($column) => str_contains($column, $forbidden)
            );

            $this->assertSame(
                [],
                $matches,
                "customer_tiers must carry no pricing: found '$forbidden'"
            );
        }
    }

    public function test_the_tier_is_available_on_the_sale_bill(): void
    {
        $tier = $this->tier(['name' => 'Wholesale']);

        $customer = Customer::create([
            'name'             => 'Acme Ltd',
            'business_id'      => $this->business->id,
            'customer_tier_id' => $tier->id,
        ]);

        DB::table('sales')->insert([
            'business_id'   => $this->business->id,
            'customer_id'   => $customer->id,
            'user_id'       => $this->user->id,
            'invoiceNumber' => 'S-1',
            'totalAmount'   => 100,
            'saleDate'      => now()->toDateString(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/sales')
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.customer.tier.name', 'Wholesale');
    }
}

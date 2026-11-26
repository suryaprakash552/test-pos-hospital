<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Guards the tenant-isolation rules that the Category policy and the
 * permission helper enforce. These previously allowed one business to
 * reach another business's records.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function userForNewBusiness(array $attributes = []): User
    {
        $business = Business::factory()->create();

        return User::factory()->create($attributes + ['business_id' => $business->id]);
    }

    public function test_category_policy_is_registered(): void
    {
        $this->assertInstanceOf(
            \App\Policies\CategoryPolicy::class,
            Gate::getPolicyFor(Category::class)
        );
    }

    public function test_user_cannot_update_or_delete_another_businesses_category(): void
    {
        $user = $this->userForNewBusiness();
        $otherUser = $this->userForNewBusiness();

        $foreignCategory = Category::factory()->create([
            'business_id' => $otherUser->business_id,
        ]);

        $this->assertFalse($user->can('update', $foreignCategory));
        $this->assertFalse($user->can('delete', $foreignCategory));
        $this->assertFalse($user->can('view', $foreignCategory));
    }

    public function test_user_can_manage_their_own_category(): void
    {
        $user = $this->userForNewBusiness();

        $ownCategory = Category::factory()->create([
            'business_id' => $user->business_id,
        ]);

        $this->assertTrue($user->can('update', $ownCategory));
        $this->assertTrue($user->can('delete', $ownCategory));
        $this->assertTrue($user->can('view', $ownCategory));
    }

    public function test_businessless_user_does_not_match_orphaned_records(): void
    {
        $user = User::factory()->create(['business_id' => null]);

        $orphan = Category::factory()->make(['business_id' => null]);

        $this->assertFalse($user->can('view', $orphan));
        $this->assertFalse($user->can('update', $orphan));
        $this->assertFalse($user->can('delete', $orphan));
    }

    public function test_has_permission_accepts_both_key_separators(): void
    {
        $user = User::factory()->make([
            'role' => 'staff',
            'business_id' => 1,
            'visibility' => ['products' => ['create' => '1']],
        ]);

        $this->assertTrue($user->hasPermission('products.create'));
        $this->assertTrue($user->hasPermission('products-create'));
    }

    /**
     * Module names contain hyphens, and the roles screen writes them that way:
     * `{"vat-reports": {"read": "1"}}`.
     *
     * Splitting at the first separator read that as module "vat", action
     * "reports.read" — matching nothing the app ever writes. Ticking a hyphenated
     * module granted nothing, and only the owner could reach it.
     */
    public function test_a_hyphenated_module_resolves_to_its_own_key(): void
    {
        $user = User::factory()->make([
            'role'        => 'staff',
            'business_id' => 1,
            'visibility'  => [
                'vat-reports'                  => ['read' => '1'],
                'transaction-history-reports'  => ['read' => '1'],
            ],
        ]);

        $this->assertTrue($user->hasPermission('vat-reports.read'));
        $this->assertTrue($user->hasPermission('transaction-history-reports.read'));
        $this->assertTrue($user->hasAnyPermission(['vat-reports.read']));
    }

    /** A hyphenated module that was not granted is still denied. */
    public function test_a_hyphenated_module_still_denies_what_was_not_granted(): void
    {
        $user = User::factory()->make([
            'role'        => 'staff',
            'business_id' => 1,
            'visibility'  => ['vat-reports' => ['read' => '0']],
        ]);

        $this->assertFalse($user->hasPermission('vat-reports.read'));
        $this->assertFalse($user->hasPermission('sale-reports.read'));
    }

    /** The hyphen-as-separator form keeps working for hyphenated modules too. */
    public function test_a_hyphenated_module_accepts_the_hyphen_separator(): void
    {
        $user = User::factory()->make([
            'role'        => 'staff',
            'business_id' => 1,
            'visibility'  => ['vat-reports' => ['read' => '1']],
        ]);

        $this->assertTrue($user->hasPermission('vat-reports-read'));
    }

    public function test_has_permission_denies_unknown_and_malformed_keys(): void
    {
        $user = User::factory()->make([
            'role' => 'staff',
            'business_id' => 1,
            'visibility' => ['products' => ['create' => '1']],
        ]);

        $this->assertFalse($user->hasPermission('products-delete'));
        // A key with no action segment must deny rather than error.
        $this->assertFalse($user->hasPermission('products'));
        $this->assertFalse($user->hasAnyPermission(['products', 'sales']));
    }

    public function test_sale_stores_and_resolves_its_customer(): void
    {
        $user = $this->userForNewBusiness();

        $customer = \App\Models\Customer::create([
            'business_id' => $user->business_id,
            'name' => 'Regression Customer',
            'phone' => '0100000000',
            'type' => 'Retailer',
            'due' => 0,
        ]);

        $sale = \App\Models\Sale::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'invoiceNumber' => 'TEST-SALE-1',
            'saleDate' => now(),
            'totalAmount' => 100,
            'paidAmount' => 100,
            'dueAmount' => 0,
            'isPaid' => 1,
            'paymentType' => 'cash',
        ]);

        // customer_id must actually persist: the Business UI posted `party_id`,
        // which is not fillable, so the customer was silently dropped.
        $this->assertSame($customer->id, $sale->fresh()->customer_id);

        // Invoice/receipt views read $sale->party, which pointed at a dropped
        // column and always rendered "Guest".
        $this->assertNotNull($sale->fresh()->party);
        $this->assertSame('Regression Customer', $sale->fresh()->party->name);
        $this->assertSame('Regression Customer', $sale->fresh()->customer->name);
    }

    public function test_sales_customer_id_is_constrained_to_customers_only(): void
    {
        $rows = DB::select(
            'select referenced_table_name as ref
               from information_schema.key_column_usage
              where constraint_schema = ?
                and table_name = ?
                and column_name = ?
                and referenced_table_name is not null',
            [DB::getDatabaseName(), 'sales', 'customer_id']
        );

        $constraints = array_map(static fn ($row) => $row->ref, $rows);

        // Two constraints on one column made most sale inserts fail.
        $this->assertSame(['customers'], $constraints);
    }
}

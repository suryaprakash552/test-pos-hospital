<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Business;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation in the web panel controllers.
 *
 * The earlier isolation audit covered `app/Http/Controllers` and the form
 * requests. It did not cover `Modules/`, and the web panel lives there — the
 * `business.*` routes served by `Modules/Business`, `Modules/HrmAddon`,
 * `Modules/MultiBranchAddon` and `Modules/WarehouseAddon`.
 *
 * Those controllers loaded tenant-owned rows with a bare `Model::findOrFail($id)`:
 *
 * ```php
 * public function update(Request $request, $id) {
 *     $brand = Brand::findOrFail($id);   // any business's brand
 *     ...
 *     $brand->update(...);
 * }
 * ```
 *
 * `BelongsToBusiness` supplies opt-in scopes only — there is no global scope —
 * and the routes carry just `users` (authenticated) and `expired`
 * (subscription) middleware. Only four models have policies. So a signed-in
 * user of one shop could edit or delete another shop's brands, units,
 * manufacturers, parties, products, income and expense categories, employees,
 * branches and warehouses by posting to the id.
 *
 * 42 lookups across 20 controllers now scope to the authenticated business, so
 * a foreign id is a 404 rather than an edit — which also stops the endpoint
 * being an enumeration oracle.
 */
class WebPanelTenantScopingTest extends TestCase
{
    use RefreshDatabase;

    private function userForNewBusiness(): User
    {
        $business = Business::factory()->create();

        return User::factory()->create(['business_id' => $business->id]);
    }

    /**
     * The scoped lookup the controllers now perform.
     *
     * Asserting the query behaviour directly rather than driving each of the 20
     * controllers through HTTP: the property is that the lookup is confined to
     * the tenant, and the routes need session, subscription and permission
     * state that is not what is under test here.
     */
    public function test_a_scoped_lookup_cannot_reach_another_businesses_row(): void
    {
        $mine = $this->userForNewBusiness();
        $theirs = $this->userForNewBusiness();

        $foreign = Brand::create([
            'brandName' => 'Their Brand',
            'business_id' => $theirs->business_id,
            'status' => 1,
        ]);

        $found = Brand::where('business_id', $mine->business_id)
            ->find($foreign->id);

        $this->assertNull(
            $found,
            'a scoped lookup must not return another business\'s brand'
        );
    }

    public function test_the_unscoped_lookup_would_have_returned_it(): void
    {
        // The behaviour being removed. Without this the test above passes for
        // the wrong reason — a lookup that finds nothing because nothing exists.
        $theirs = $this->userForNewBusiness();

        $foreign = Brand::create([
            'brandName' => 'Their Brand',
            'business_id' => $theirs->business_id,
            'status' => 1,
        ]);

        $this->assertNotNull(Brand::find($foreign->id));
    }

    public function test_a_business_still_reaches_its_own_rows(): void
    {
        $mine = $this->userForNewBusiness();

        $own = Unit::create([
            'unitName' => 'Box',
            'business_id' => $mine->business_id,
            'status' => 1,
        ]);

        $this->assertNotNull(
            Unit::where('business_id', $mine->business_id)->find($own->id),
            'scoping must not lock a business out of its own data'
        );
    }

    /**
     * The controllers themselves, scanned.
     *
     * The fix has to hold in every one of the 20 files; a test that drives one
     * controller says nothing about the other nineteen.
     */
    public function test_no_module_controller_loads_a_tenant_row_unscoped(): void
    {
        $tenantModels = [
            'Brand', 'Unit', 'Manufacturer', 'Party', 'Product', 'Sale',
            'Purchase', 'ExpenseCategory', 'IncomeCategory', 'Attendance',
            'Department', 'Designation', 'Employee', 'Leave', 'Payroll',
            'Shift', 'Branch', 'Warehouse',
        ];

        $offenders = [];

        foreach (glob(base_path('Modules/*/App/Http/Controllers/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach ($tenantModels as $model) {
                $pattern = '/' . $model . '::findOrFail\(/';

                if (preg_match($pattern, $source)) {
                    $offenders[] = basename($file) . ": {$model}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these load a tenant-owned row without scoping it to the business:\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * Lookup ids validated against the whole table.
     *
     * A bare `exists:categories,id` accepts another shop's category, and the
     * ids are mass-assigned onto the row. `vat_id` is the costly one: a foreign
     * VAT applies someone else's tax rate to this shop's sales.
     */
    public function test_no_module_controller_validates_a_lookup_id_globally(): void
    {
        $tenantTables = [
            'units', 'brands', 'manufacturers', 'categories', 'parties',
            'payment_types', 'income_categories', 'expense_categories',
            'vats', 'customers',
        ];

        $offenders = [];

        foreach (glob(base_path('Modules/*/App/Http/Controllers/*.php')) as $file) {
            $source = file_get_contents($file);

            foreach ($tenantTables as $table) {
                if (str_contains($source, "exists:{$table},id")) {
                    $offenders[] = basename($file) . ": {$table}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "use TenantOwned::in(...) so the id is confined to the business:\n"
                . implode("\n", $offenders)
        );
    }
}

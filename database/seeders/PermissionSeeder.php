<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $rolesStructure = [
        'Super Admin' => [

                // ================= EXISTING =================
                'dashboard' => 'r',
                'users' => 'r,c,u,d',
                'banners' => 'r,c,u,d',
                'business' => 'r,c,u,d',
                'addons' => 'r,c',
                'business-categories' => 'r,c,u,d',
                'plans' => 'r,c,u,d',
                'subscription-reports' => 'r',
                'subscription-orders' => 'r',
                'features' => 'r,c,u,d',
                'domains' => 'r,c,u,d',
                'interfaces' => 'r,c,u,d',
                'testimonials' => 'r,c,u,d',
                'blogs' => 'r,c,u,d',
                'messages' => 'r,c,u,d',
                'terms' => 'r,u',
                'gateways' => 'r,u',
                'currencies' => 'r,c,u,d',
                'settings' => 'r,u',
                'roles' => 'r,c,u,d',
                'permissions' => 'r,c',
                'notifications' => 'r,u',
                'manufacturers' => 'r,c,u,d',

                // ================= SALES / PURCHASE =================
                'sales' => 'r,c,u,d',
                'inventory' => 'c',
                'sale-returns' => 'r',
                'purchases' => 'r,c,u,d',
                'purchase-returns' => 'r',

                // ================= PRODUCTS =================
                'products' => 'r,c,u,d',
                'products-expired' => 'r',
                'barcodes' => 'r',
                'bulk-uploads' => 'r',
                'categories' => 'r,c,u,d',
                'brands' => 'r',
                'units' => 'r',
                'product-models' => 'r',
                'racks' => 'r',
                'shelfs' => 'r',

                // ================= WAREHOUSE (ADDON) =================
                'warehouses' => 'r',
                'transfers' => 'r',

                // ================= MULTI BRANCH (ADDON) =================
                'branches' => 'r',

                // ================= STOCK =================
                'stocks' => 'r',
                'expired-products' => 'r',

                // ================= PARTIES =================
                'parties' => 'r,c',

                // ================= INCOME / EXPENSE =================
                'incomes' => 'r',
                'income-categories' => 'r',
                'expenses' => 'r',
                'expense-categories' => 'r',

                // ================= VAT / DUES =================
                'vats' => 'r',
                'dues' => 'r',

                // ================= HRM (ADDON) =================
                'department' => 'r,c,u,d',
                'designations' => 'r,c,u,d',
                'shifts' => 'r,c,u,d',
                'employees' => 'r,c,u,d',
                'leave-types' => 'r,c,u,d',
                'leaves' => 'r,c,u,d',
                'holidays' => 'r,c,u,d',
                'attendances' => 'r,c,u,d',
                'payrolls' => 'r,c,u,d',

                // ================= HRM REPORTS =================
                'attendance-reports' => 'r',
                'payroll-reports' => 'r',
                'leave-reports' => 'r',

                // ================= REPORTS =================
                'sale-reports' => 'r',
                'sale-return-reports' => 'r',
                'purchase-reports' => 'r',
                'purchase-return-reports' => 'r',
                'vat-reports' => 'r',
                'income-reports' => 'r',
                'expense-reports' => 'r',
                'loss-profits' => 'r',
                'loss-profits-details' => 'r',
                'stock-reports' => 'r',
                'due-reports' => 'r',
                'supplier-due-reports' => 'r',
                'transaction-history-reports' => 'r',
                'expired-product-reports' => 'r',

                // ================= GST CONFIGURATION =================
                // §19: who may see how the shop bills tax, change it, and override a
                // branch that was locked to its shop type's default.
                'gst' => 'r,u,o,b,s,i,t,m',

                // ================= LOYALTY (§13) =================
                // read / create / update / assign / renew / upgrade / cancel /
                // manual discount / override automatic discount.
                'loyalty' => 'r,c,u,a,n,g,x,k,v,y,e,f',

                // ================= SETTINGS =================
                'manage-settings' => 'r',
                'payment-types' => 'r',

                // ================= MISC =================
                'subscriptions' => 'r',
                'download-apk' => 'r',
            ],


            'Admin' => [
                'dashboard' => 'r',
                'users' => 'r,c,u,d',
                'banners' => 'r,c,u,d',
                'business' => 'r,c,u,d',
                'addons' => 'r,c',
                'business-categories' => 'r,c,u,d',
                'plans' => 'r,c,u,d',
                'domains' => 'r,c,u,d',
                'subscription-reports' => 'r',
                'features' => 'r,c,u,d',
                'interfaces' => 'r,c,u,d',
                'testimonials' => 'r,c,u,d',
                'blogs' => 'r,c,u,d',
                'messages' => 'r,c,u,d',
                'terms' => 'r,u',
                'currencies' => 'r,c,u,d',
                'manufacturers' => 'r,c,u,d',
            ],

            'Manager' => [
                'dashboard' => 'r',
                'banners' => 'r,c,u,d',
                'business-categories' => 'r,c,u,d',
                'plans' => 'r,c,u,d',
                'subscription-reports' => 'r',
                'features' => 'r,c,u,d',
                'interfaces' => 'r,c,u,d',
                'testimonials' => 'r,c,u,d',
                'blogs' => 'r,c,u,d',
                'messages' => 'r,c,u,d',
                'terms' => 'r,u',
                'manufacturers' => 'r,c,u,d',
            ]
        ];

        foreach ($rolesStructure as $key => $modules) {
            // Create a new role
            $role = Role::firstOrCreate([
                'name' => str($key)->remove(' ')->lower(),
                'guard_name' => 'web'
            ]);
            $permissions = [];

            $this->command->info('Creating Role ' . strtoupper($key));

            // Reading role permission modules
            foreach ($modules as $module => $value) {

                foreach (explode(',', $value) as $perm) {

                    $permissionValue = $this->permissionMap()->get($perm);

                    $permissions[] = Permission::firstOrCreate([
                        'name' => $module . '-' . $permissionValue,
                        'guard_name' => 'web'
                    ])->id;

                    $this->command->info('Creating Permission to ' . $permissionValue . ' for ' . $module);
                }
            }

            // Attach all permissions to the role
            $role->permissions()->sync($permissions);

            $this->command->info("Creating '{$key}' user");
            // Create default user for each role
            $user = User::create([
                'role' => str($key)->remove(' ')->lower(),
                'name' => ucwords(str_replace('_', ' ', $key)),
                'password' => bcrypt(str($key)->remove(' ')->lower()),
                'email' => str($key)->remove(' ')->lower() . '@' . str($key)->remove(' ')->lower() . '.com',
                'image' => 'assets/images/profile/' . str($key)->remove(' ')->lower() . '.png',
            ]);

            $user->assignRole($role);
        }
    }

    /**
     * Exposed so `PermissionMapTest` can assert the letters resolve as intended.
     *
     * A duplicate key here is silent and its effect is remote - it shows up as a
     * permission nobody was granted, modules away from this file.
     */
    public function exposedPermissionMap()
    {
        return $this->permissionMap();
    }

    private function permissionMap()
    {
        return collect([
            'c' => 'create',
            'r' => 'read',
            'u' => 'update',
            'd' => 'delete',
            // §19: overriding a branch locked to its shop type's default is its own
            // permission, not an ordinary update.
            'o' => 'override',
            // §17: dating a change into a period already filed contradicts a return
            // that has gone out, so it is not an ordinary override either.
            'b' => 'backdate',
            // §19: the rest of the GST permissions, each its own decision —
            // shop-type defaults are administrator-level, a GSTIN identifies the
            // business to the tax authority, and an item-level override changes
            // what a single product is charged.
            's' => 'shop-type-defaults',
            'i' => 'gstin',
            't' => 'rates',
            'm' => 'item-override',
            // §13 loyalty actions, each separately grantable.
            //
            // Every letter here has to be unique across the whole map: it is one
            // array, and a repeated key silently keeps only the last value.
            // 'm' and 'd' were reused below and quietly destroyed the two
            // entries above them - `item-override` became unreachable, and, far
            // worse, `'d' => 'delete'` was replaced by `modify-dates`, so EVERY
            // module's delete permission was created as `<module>-modify-dates`
            // and `<module>-delete` never existed. Deleting anything was refused
            // while creating and updating worked, because 'c' and 'u' did not
            // collide.
            'a' => 'assign',
            'n' => 'renew',
            'g' => 'upgrade',
            'x' => 'cancel',
            'k' => 'manual-discount',
            'v' => 'override',
            // §13's remaining three: changing a package's dates, exporting a
            // loyalty report, and refunding a subscription are each their own
            // decision and are grantable separately.
            'y' => 'modify-dates',
            'e' => 'export-reports',
            'f' => 'process-refunds',
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sales.party_id` was renamed to `customer_id` (2026_03_24_071053), which carried
 * the original `sales_party_id_foreign` -> parties(id) constraint over to the new
 * column name. A later migration (2026_04_13_111913) then added
 * `sales_customer_id_foreign` -> customers(id) without dropping the old one.
 *
 * That left one column constrained against two different tables, so an insert only
 * succeeded when the id happened to exist in `parties` as well as `customers`.
 * Sales are keyed to customers, so the stale parties constraint is the one to drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->foreignKeyExists('sales_party_id_foreign')) {
            Schema::table('sales', function ($table) {
                $table->dropForeign('sales_party_id_foreign');
            });
        }
    }

    public function down(): void
    {
        // Intentionally not restored: recreating it would reintroduce the
        // conflicting dual-constraint state this migration exists to remove.
    }

    private function foreignKeyExists(string $name): bool
    {
        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', 'sales')
            ->where('constraint_name', $name)
            ->exists();
    }
};

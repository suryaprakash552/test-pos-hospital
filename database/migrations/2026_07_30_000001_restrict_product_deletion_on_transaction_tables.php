<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sale_details.product_id`, `purchase_details.product_id` and
 * `transfer_products.product_id` were all created with
 * `->constrained()->cascadeOnDelete()`. Deleting a product therefore deleted its
 * historical sale lines, purchase lines and branch-transfer lines along with it —
 * silently, and with no way to recover them.
 *
 * The damage is not limited to the product being removed: profit/loss, stock audit
 * and any tax report aggregate over these tables, so a single delete rewrites
 * historical figures for periods that were already closed.
 *
 * These three tables are transaction history and must outlive the product record.
 * Switching them to RESTRICT makes the database refuse the delete; the API layer
 * (AcnooProductController::destroy) reports the blocking counts and offers an
 * owner-authorised override that clears the dependent rows deliberately.
 *
 * `stocks.product_id` is deliberately left as CASCADE. A stock batch has no meaning
 * without its product, and deleting a stock row only SET NULLs `stock_id` on the
 * detail tables rather than removing them, so that path does not destroy history.
 */
return new class extends Migration
{
    /**
     * product_id foreign keys that must block deletion, keyed by table.
     */
    private const TABLES = [
        'sale_details'      => 'sale_details_product_id_foreign',
        'purchase_details'  => 'purchase_details_product_id_foreign',
        'transfer_products' => 'transfer_products_product_id_foreign',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $constraint) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')) {
                continue;
            }

            // A cascade delete may already have run, so verify there is nothing
            // dangling before adding a constraint that would reject it.
            $orphans = DB::table($table)
                ->whereNotNull('product_id')
                ->whereNotExists(function ($query) use ($table) {
                    $query->select(DB::raw(1))
                        ->from('products')
                        ->whereColumn('products.id', "{$table}.product_id");
                })
                ->count();

            if ($orphans > 0) {
                throw new RuntimeException(
                    "Cannot restrict {$table}.product_id: {$orphans} row(s) reference a "
                    . 'product that no longer exists. Reconcile those rows first — they are '
                    . 'transaction history and must not be deleted to make this migration pass.'
                );
            }

            $this->dropForeignIfExists($table, $constraint);

            Schema::table($table, function ($blueprint) {
                $blueprint->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $constraint) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'product_id')) {
                continue;
            }

            $this->dropForeignIfExists($table, $constraint);

            Schema::table($table, function ($blueprint) {
                $blueprint->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();
            });
        }
    }

    /**
     * Drop by the constraint's real name, which may differ from Laravel's
     * convention on databases that were migrated by hand.
     */
    private function dropForeignIfExists(string $table, string $expected): void
    {
        $names = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', 'product_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        foreach ($names ?: [$expected] as $name) {
            Schema::table($table, function ($blueprint) use ($name) {
                $blueprint->dropForeign($name);
            });
        }
    }
};

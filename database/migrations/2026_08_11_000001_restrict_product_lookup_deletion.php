<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `products.category_id`, `products.brand_id`, `products.unit_id` and
 * `products.manufacturer_id` were all created with `->constrained()->cascadeOnDelete()`.
 *
 * Deleting a category therefore deleted every product in it. Deleting a brand
 * deleted every product of that brand; the same for a unit and a manufacturer.
 * These are lookup lists that a shopkeeper tidies up — renaming, merging,
 * removing an entry added by mistake — and the delete button beside each row is
 * offered as an ordinary edit. Nothing warned that pressing it discards the
 * catalogue.
 *
 * The loss compounds with the transaction tables: since
 * 2026_07_30_000001 those RESTRICT on `product_id`, so a cascade that reaches a
 * product with sale history now aborts the whole statement and reports a bare
 * foreign-key error instead. Before that migration it went through and took the
 * sale lines with it.
 *
 * `products.rack_id` and `products.shelf_id` are deliberately untouched: they
 * were created `nullOnDelete`, which is right for a storage location. Removing a
 * rack should clear where a product is kept, not remove the product.
 *
 * RESTRICT makes the database refuse; the API layer checks first and reports how
 * many products are in the way, so the message names a number the user can act
 * on rather than surfacing a driver error.
 */
return new class extends Migration
{
    /**
     * products columns that must block deletion of the record they point at,
     * mapped to the table they reference.
     */
    private const COLUMNS = [
        'category_id'     => 'categories',
        'brand_id'        => 'brands',
        'unit_id'         => 'units',
        'manufacturer_id' => 'manufacturers',
    ];

    public function up(): void
    {
        $this->apply('restrict');
    }

    public function down(): void
    {
        $this->apply('cascade');
    }

    private function apply(string $mode): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        foreach (self::COLUMNS as $column => $referenced) {
            if (! Schema::hasColumn('products', $column) || ! Schema::hasTable($referenced)) {
                continue;
            }

            if ($mode === 'restrict') {
                $this->guardOrphans($column, $referenced);
            }

            $this->dropForeignIfExists('products', $column);

            Schema::table('products', function ($blueprint) use ($column, $referenced, $mode) {
                $foreign = $blueprint->foreign($column)
                    ->references('id')
                    ->on($referenced);

                $mode === 'restrict'
                    ? $foreign->restrictOnDelete()
                    : $foreign->cascadeOnDelete();
            });
        }
    }

    /**
     * A cascade may already have run, leaving products pointing at a lookup row
     * that no longer exists. RESTRICT would reject those, so fail with something
     * that says which column and how many rather than a driver error.
     */
    private function guardOrphans(string $column, string $referenced): void
    {
        $orphans = DB::table('products')
            ->whereNotNull($column)
            ->whereNotExists(function ($query) use ($column, $referenced) {
                $query->select(DB::raw(1))
                    ->from($referenced)
                    ->whereColumn("{$referenced}.id", "products.{$column}");
            })
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot restrict products.{$column}: {$orphans} product(s) reference a "
                . "{$referenced} row that no longer exists. Reconcile those products first "
                . '— point them at a valid record, or clear the column where it is nullable.'
            );
        }
    }

    /**
     * Drop by the constraint's real name, which may differ from Laravel's
     * convention on databases that were migrated by hand.
     */
    private function dropForeignIfExists(string $table, string $column): void
    {
        $names = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->all();

        foreach ($names as $name) {
            Schema::table($table, function ($blueprint) use ($name) {
                $blueprint->dropForeign($name);
            });
        }
    }
};

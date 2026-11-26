<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transaction history was reachable by cascade from records people delete.
 *
 * `sales.user_id` and `purchases.user_id` were `cascadeOnDelete`, so removing a
 * staff member who had left deleted every sale and purchase they had ever
 * recorded — and `AcnooUserController::destroy` had no guard of any kind, so a
 * single tap on a user row rewrote the business's revenue figures.
 *
 * `purchases.party_id` and the `due_collects` keys are the same shape for
 * suppliers. `PartyController::destroy` checked only that
 * the party had no outstanding balance, which a long-standing customer who always
 * paid on time will always satisfy — so the best customers were the easiest to
 * erase.
 *
 * `expenses.user_id` and `incomes.user_id` complete the set: staff removal took
 * the books with it.
 *
 * `products.vat_id` is included for a different reason. The migration that added
 * it wrote `nullOnDelete` for `sales.vat_id` and `purchases.vat_id` in the same
 * block, and `cascadeOnDelete` only for products — deleting a tax rate deleted
 * every product on it. The inconsistency inside one block is what marks it as a
 * slip rather than a decision.
 *
 * `users.branch_id` likewise: closing a branch deleted its staff accounts.
 *
 * RESTRICT is what enforces this; the API layer checks first (see
 * App\Support\LookupDeletionGuard) so the refusal names what is in the way
 * instead of surfacing a driver error.
 *
 * Not changed: `business_id` cascades (removing a business should remove its
 * data), and parent/child compositions such as `sale_details.sale_id` — a detail
 * line has no meaning without its sale.
 */
return new class extends Migration
{
    /**
     * [table, column, referenced table] that must block deletion.
     */
    private const KEYS = [
        // Not listed: sales.party_id. It was renamed to customer_id in
        // 2026_03_24_071053 and now points at `customers` with nullOnDelete, so
        // a sale survives its customer being removed - it just loses the link.

        ['sales',         'user_id',     'users'],
        ['purchases',     'party_id',    'parties'],
        ['purchases',     'user_id',     'users'],
        ['expenses',      'user_id',     'users'],
        ['incomes',       'user_id',     'users'],
        ['due_collects',  'party_id',    'parties'],
        ['due_collects',  'user_id',     'users'],
        ['due_collects',  'customer_id', 'customers'],
        ['products',      'vat_id',      'vats'],
        ['users',         'branch_id',   'branches'],
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
        foreach (self::KEYS as [$table, $column, $referenced]) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, $column)
                || ! Schema::hasTable($referenced)) {
                continue;
            }

            if ($mode === 'restrict') {
                $this->guardOrphans($table, $column, $referenced);
            }

            $this->dropForeignIfExists($table, $column);

            Schema::table($table, function ($blueprint) use ($column, $referenced, $mode) {
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
     * A cascade may already have run and left rows pointing at a record that no
     * longer exists. RESTRICT rejects those, so fail with something that says
     * which key and how many rather than a driver error mid-deploy.
     */
    private function guardOrphans(string $table, string $column, string $referenced): void
    {
        $orphans = DB::table($table)
            ->whereNotNull($column)
            ->whereNotExists(function ($query) use ($table, $column, $referenced) {
                $query->select(DB::raw(1))
                    ->from($referenced)
                    ->whereColumn("{$referenced}.id", "{$table}.{$column}");
            })
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot restrict {$table}.{$column}: {$orphans} row(s) reference a "
                . "{$referenced} record that no longer exists. Reconcile those rows first "
                . '— they are history and must not be deleted to make this migration pass.'
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

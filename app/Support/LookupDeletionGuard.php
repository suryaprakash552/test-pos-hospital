<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refuses to delete a lookup record that products still point at.
 *
 * `products.category_id`, `brand_id`, `unit_id` and `manufacturer_id` were
 * created `cascadeOnDelete`, so removing a category deleted every product in it.
 * The delete button on those list screens is offered as an ordinary edit, beside
 * rename, and nothing said it discards the catalogue.
 *
 * Migration 2026_08_11_000001 switches those keys to RESTRICT, which is what
 * actually protects the data — the database refuses regardless of which code
 * path asks. This runs first so the answer is a sentence naming a number
 * ("3 products still use this category") instead of a foreign-key violation
 * surfacing as a 500.
 *
 * Deliberately not applied to racks and shelves: those are `nullOnDelete`, and
 * removing a storage location should clear where a product is kept rather than
 * being blocked by it.
 */
class LookupDeletionGuard
{
    /**
     * Products still referencing this record, across every business.
     *
     * Deliberately not scoped to the caller's business. This guard exists to
     * mirror the RESTRICT constraint, and the constraint does not know about
     * tenancy — scoping the count differently from the thing it stands in for
     * means the guard passes a delete the database then rejects, turning a
     * clear 409 into a foreign-key 500.
     *
     * A lookup row carries its own `business_id`, so in a consistent database
     * every product referencing it belongs to that same business and the two
     * counts are identical. They differ only where the data is already wrong,
     * and there the safe answer is to refuse.
     */
    public static function productCount(string $column, int|string $id): int
    {
        return Product::withoutGlobalScopes()
            ->where($column, $id)
            ->count();
    }

    /**
     * The message shown when a delete is refused, or null when it may proceed.
     *
     * @param  string  $noun  Lower-case singular, e.g. "category".
     */
    public static function blockedMessage(
        string $column,
        int|string $id,
        string $noun
    ): ?string {
        $count = self::productCount($column, $id);

        if ($count === 0) {
            return null;
        }

        return __(
            ':count product(s) still use this :noun. Move them to another :noun first, '
            . 'then delete it.',
            ['count' => $count, 'noun' => $noun]
        );
    }

    /**
     * Rows in [$table] whose [$column] points at [$id].
     *
     * Runs on the query builder rather than a model so a caller does not need an
     * Eloquent class for every table it wants to check, and so no global scope
     * can quietly narrow a count that exists to mirror a database constraint.
     */
    public static function countReferences(string $table, string $column, int|string $id): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->where($column, $id)->count();
    }

    /**
     * Refuses a delete that would destroy history, naming what is in the way.
     *
     * `sales.user_id`, `purchases.user_id`, `expenses.user_id`, `incomes.user_id`
     * and the `party_id` equivalents were all `cascadeOnDelete`. Removing a staff
     * member who had left therefore deleted every sale they had ever rung up, and
     * removing a settled customer deleted their whole trading history — silently,
     * and taking the revenue figures with them.
     *
     * @param  array<string, array{0: string, 1: string}>  $checks
     *         Label => [table, column], e.g. ['sales' => ['sales', 'user_id']].
     * @return string|null  Null when nothing references the record.
     */
    public static function blockedByHistory(array $checks, int|string $id, string $noun): ?string
    {
        $blocking = [];

        foreach ($checks as $label => [$table, $column]) {
            $count = self::countReferences($table, $column, $id);

            if ($count > 0) {
                $blocking[] = "{$count} {$label}";
            }
        }

        if ($blocking === []) {
            return null;
        }

        return __(
            'This :noun cannot be deleted: it is referenced by :details. That history '
            . 'has to stay. Deactivate the :noun instead.',
            ['noun' => $noun, 'details' => implode(', ', $blocking)]
        );
    }
}

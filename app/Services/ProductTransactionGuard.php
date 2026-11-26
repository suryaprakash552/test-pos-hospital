<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseDetails;
use App\Models\SaleDetails;
use App\Models\TransferProduct;
use Illuminate\Support\Facades\DB;

/**
 * Answers one question: has this product been transacted?
 *
 * A product that appears on a sale, a purchase or a branch transfer is referenced by
 * historical records that must outlive it. Those records are what profit/loss, stock
 * audit and tax reporting aggregate over, so mutating or removing the product changes
 * figures for periods that were already closed.
 *
 * Two callers need this answer:
 *  - deletion (AcnooProductController::destroy) — block, or require an owner override
 *  - conversion value changes — block, because rewriting the unit conversion
 *    retroactively reinterprets every historical quantity recorded against it
 *
 * Counts are read straight from the detail tables by `product_id`. Product::purchaseDetails()
 * deliberately is not used: it resolves through `stocks`, so it misses detail rows whose
 * `stock_id` was nulled when a batch was removed.
 */
class ProductTransactionGuard
{
    /**
     * Number of historical rows referencing the product, keyed by kind.
     * Only non-zero entries are returned, so an empty array means "never transacted".
     *
     * @return array<string, int>
     */
    public static function usage(Product $product): array
    {
        $counts = [
            'sales'     => SaleDetails::where('product_id', $product->id)->count(),
            'purchases' => PurchaseDetails::where('product_id', $product->id)->count(),
            'transfers' => TransferProduct::where('product_id', $product->id)->count(),
        ];

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    public static function hasTransactions(Product $product): bool
    {
        return self::usage($product) !== [];
    }

    /**
     * Everything that makes a product undeletable, not only what transacted it.
     *
     * Deliberately wider than {@see usage()}, and kept separate from it. `usage()`
     * answers "has this been bought or sold", which is the right question for
     * whether the unit conversion may still be changed. Deletion is a different
     * question: a batch with no purchase behind it never transacted, but removing
     * the product takes that batch with it, because `stocks.product_id` cascades.
     * Those are the legacy rows the phantom-stock survey found, and they are
     * evidence to be preserved until there is a controlled cleanup for them.
     *
     * Inventory events are included even though nothing in the database enforces
     * it: `inventory_change_logs` carries no foreign key, so deleting a product
     * leaves its immutable history pointing at an id that no longer resolves.
     * Only the application can prevent that.
     *
     * @return array<string, int> non-zero counts only; empty means safe to delete
     */
    public static function deletionBlockers(Product $product): array
    {
        $counts = self::usage($product) + [
            'batches' => DB::table('stocks')
                ->where('product_id', $product->id)
                ->count(),
            'inventory_events' => DB::table('inventory_change_logs')
                ->where('product_id', $product->id)
                ->count(),
            'stock_audits' => DB::table('stock_audits')
                ->where('product_id', $product->id)
                ->count(),
            'disposals' => DB::table('stock_disposal_lines')
                ->where('product_id', $product->id)
                ->count(),
        ];

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    public static function isDeletable(Product $product): bool
    {
        return self::deletionBlockers($product) === [];
    }

    /**
     * The refusal, in the shape every caller returns it.
     *
     * Built here so the web panel and the API cannot drift into explaining the
     * same protection two different ways.
     *
     * `archive_available` is false because this database has no status, archive
     * or soft-delete column on products: there is currently nothing to fall back
     * to, and telling a client to archive when it cannot would be a lie it would
     * have to discover by trying.
     *
     * @param array<string,int> $blockers
     * @return array<string,mixed>
     */
    public static function protectedResponse(array $blockers): array
    {
        return [
            'success' => false,
            'code' => 'PRODUCT_HISTORY_PROTECTED',
            'message' => __('This product cannot be deleted because inventory or transaction history exists.'),
            'action' => 'archive_or_disable',
            'archive_available' => false,
            'history' => $blockers,
            'history_text' => self::describe($blockers),
        ];
    }

    /**
     * Human-readable summary for API messages, e.g. "3 sale(s), 1 purchase(s)".
     */
    public static function describe(array $usage): string
    {
        $labels = [
            'sales'            => 'sale',
            'purchases'        => 'purchase',
            'transfers'        => 'branch transfer',
            'batches'          => 'stock batch',
            'inventory_events' => 'inventory event',
            'stock_audits'     => 'stock audit',
            'disposals'        => 'disposal record',
        ];

        $parts = [];
        foreach ($usage as $key => $count) {
            $parts[] = $count . ' ' . ($labels[$key] ?? $key) . ($count === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }
}

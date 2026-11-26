<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * How many products a business holds, split by stock position.
 *
 * The app used to count these itself, from whichever product list happened to be
 * in memory. That gave wrong figures the moment a category was chosen: the grid
 * switches to server-side paging, but the counts were still computed by
 * re-filtering a separate unpaginated list on `category.id` - which silently drops
 * every product whose category relation was absent from the payload.
 *
 * Counting here fixes both halves of that. It is one query over the whole
 * catalogue, so it does not care what the client has paged in, and it uses the
 * same measure the sale endpoint validates against - the sum of
 * `purchase_details.conversionQuantity` - so a product counted "in stock" here is
 * one that can actually be sold.
 */
class ProductStockSummary
{
    /**
     * @return array{total: int, in_stock: int, low_stock: int, out_of_stock: int}
     */
    public function forBusiness(int $businessId, ?int $categoryId = null): array
    {
        // Available quantity per product, as a correlated sum rather than a join:
        // a join would multiply the product row per batch and miscount the total.
        $available = DB::table('purchase_details')
            ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
            ->whereColumn('stocks.product_id', 'products.id')
            ->where('stocks.business_id', $businessId)
            ->selectRaw('COALESCE(SUM(purchase_details.conversionQuantity), 0)');

        $rows = DB::table('products')
            ->where('products.business_id', $businessId)
            ->when($categoryId !== null, fn ($query) => $query->where('products.category_id', $categoryId))
            ->selectSub($available, 'available_quantity')
            // COALESCE, because a null alert quantity means "no threshold set",
            // not "warn at zero" - otherwise every product reads as low stock.
            ->selectRaw('COALESCE(products.alert_qty, 0) as alert_qty')
            ->get();

        $total = $rows->count();
        $outOfStock = 0;
        $lowStock = 0;

        foreach ($rows as $row) {
            $quantity = (float) $row->available_quantity;
            $alertQuantity = (float) $row->alert_qty;

            if ($quantity <= 0) {
                $outOfStock++;
                continue;
            }

            // Only a configured threshold makes a product "low"; without one it is
            // simply in stock.
            if ($alertQuantity > 0 && $quantity <= $alertQuantity) {
                $lowStock++;
            }
        }

        return [
            'total' => $total,
            // Derived, so the four always sum to the total and the cards can never
            // disagree with each other.
            'in_stock' => $total - $lowStock - $outOfStock,
            'low_stock' => $lowStock,
            'out_of_stock' => $outOfStock,
        ];
    }
}

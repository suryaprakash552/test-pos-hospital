<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Which of a cart's value a loyalty discount may be calculated on (§8.1 step 4, §11).
 *
 * Computed from the cart's own lines rather than taken from the client. The eligible
 * amount decides how large a discount is, so accepting it from the request would let
 * a caller inflate their own discount simply by claiming a larger base — the same
 * reason the discount figure itself is resolved server-side.
 *
 * A line is excluded when its product, its brand or its category is on the exclusion
 * list for the package in question (or for every package). One query answers that for
 * a whole cart, so this stays a single lookup no matter how many lines there are.
 */
class LoyaltyEligibility
{
    public const PRODUCT = 'product';
    public const BRAND = 'brand';
    public const CATEGORY = 'category';

    /**
     * Splits a cart into what a discount may apply to and what it may not.
     *
     * @param array<int, array{product_id?: int, stock_id?: int, subtotal?: float,
     *                         price?: float, quantities?: float}> $lines
     *
     * @return array{eligible: float, ineligible: float, excluded_products: array<int, int>}
     */
    public static function split(int $businessId, array $lines, ?int $packageId = null): array
    {
        // A sale line identifies a batch, not a product — the product is reached
        // through it. Resolved in one query for the whole cart rather than per line.
        $productByStock = self::productsForStocks($lines);

        $productIds = array_values(array_unique(array_merge(
            array_values($productByStock),
            array_filter(array_map(
                fn ($line) => isset($line['product_id']) ? (int) $line['product_id'] : null,
                $lines,
            )),
        )));

        if ($productIds === []) {
            return ['eligible' => 0.0, 'ineligible' => 0.0, 'excluded_products' => []];
        }

        $excluded = self::excludedProductIds($businessId, $productIds, $packageId);

        $eligible = 0.0;
        $ineligible = 0.0;

        foreach ($lines as $line) {
            $value = self::lineValue($line);
            $productId = self::productIdFor($line, $productByStock);

            if ($productId !== null && isset($excluded[$productId])) {
                $ineligible += $value;
            } else {
                $eligible += $value;
            }
        }

        return [
            'eligible'          => round(max($eligible, 0), 2),
            'ineligible'        => round(max($ineligible, 0), 2),
            'excluded_products' => array_keys($excluded),
        ];
    }

    /**
     * Of the given products, which are excluded — directly, or through their brand
     * or category.
     *
     * @return array<int, true> keyed by product id for O(1) lookup per line
     */
    public static function excludedProductIds(
        int $businessId,
        array $productIds,
        ?int $packageId = null,
    ): array {
        $rules = DB::table('loyalty_exclusions')
            ->where('business_id', $businessId)
            // Null covers every package; a package-specific rule adds to it.
            ->where(fn ($q) => $q->whereNull('loyalty_package_id')
                ->when($packageId, fn ($w) => $w->orWhere('loyalty_package_id', $packageId)))
            ->get(['excludable_type', 'excludable_id']);

        if ($rules->isEmpty()) {
            return [];
        }

        $byType = $rules->groupBy('excludable_type')
            ->map(fn ($g) => $g->pluck('excludable_id')->all());

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'brand_id', 'category_id']);

        $excluded = [];

        foreach ($products as $product) {
            $isExcluded =
                in_array($product->id, $byType[self::PRODUCT] ?? [], false)
                || ($product->brand_id !== null
                    && in_array($product->brand_id, $byType[self::BRAND] ?? [], false))
                || ($product->category_id !== null
                    && in_array($product->category_id, $byType[self::CATEGORY] ?? [], false));

            if ($isExcluded) {
                $excluded[$product->id] = true;
            }
        }

        return $excluded;
    }

    /**
     * Every product in the shop this package will not discount.
     *
     * Answered by one query that returns *only* the excluded rows, rather than
     * enumerating the catalogue and matching in PHP. A medical store carries tens
     * of thousands of products; loading them all to find the handful that are
     * excluded made a customer lookup at the till proportional to catalogue size,
     * which is the slowest thing that can happen on the busiest screen.
     *
     * @return array<int, int>
     */
    public static function excludedProductIdsForBusiness(
        int $businessId,
        ?int $packageId = null,
    ): array {
        $rules = DB::table('loyalty_exclusions')
            ->where('business_id', $businessId)
            ->where(fn ($q) => $q->whereNull('loyalty_package_id')
                ->when($packageId, fn ($w) => $w->orWhere('loyalty_package_id', $packageId)))
            ->get(['excludable_type', 'excludable_id']);

        // Nothing excluded is the common case, and it costs one cheap query.
        if ($rules->isEmpty()) {
            return [];
        }

        $byType = $rules->groupBy('excludable_type')
            ->map(fn ($g) => $g->pluck('excludable_id')->all());

        $products = $byType[self::PRODUCT] ?? [];
        $brands = $byType[self::BRAND] ?? [];
        $categories = $byType[self::CATEGORY] ?? [];

        return DB::table('products')
            ->where('business_id', $businessId)
            ->where(function ($query) use ($products, $brands, $categories) {
                // Each clause is indexed; an empty rule set contributes nothing
                // rather than matching everything.
                if ($products !== []) {
                    $query->orWhereIn('id', $products);
                }
                if ($brands !== []) {
                    $query->orWhereIn('brand_id', $brands);
                }
                if ($categories !== []) {
                    $query->orWhereIn('category_id', $categories);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Maps the cart's stock ids to their products.
     *
     * @return array<int, int> stock id => product id
     */
    private static function productsForStocks(array $lines): array
    {
        $stockIds = array_values(array_filter(array_map(
            fn ($line) => isset($line['stock_id']) ? (int) $line['stock_id'] : null,
            $lines,
        )));

        if ($stockIds === []) {
            return [];
        }

        return DB::table('stocks')
            ->whereIn('id', $stockIds)
            ->pluck('product_id', 'id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The product a line refers to, whether it named one or a batch. */
    private static function productIdFor(array $line, array $productByStock): ?int
    {
        if (isset($line['product_id'])) {
            return (int) $line['product_id'];
        }

        $stockId = isset($line['stock_id']) ? (int) $line['stock_id'] : null;

        return $stockId !== null ? ($productByStock[$stockId] ?? null) : null;
    }

    /**
     * What a line is worth after its own row discount.
     *
     * §8.1 applies row-level discounts before the loyalty discount, so the value
     * used here is the discounted one — calculating loyalty on the undiscounted
     * price would give away more than the package promises.
     */
    private static function lineValue(array $line): float
    {
        if (isset($line['subtotal'])) {
            return (float) $line['subtotal'];
        }

        $gross = (float) ($line['price'] ?? 0) * (float) ($line['quantities'] ?? 0);

        return max($gross - (float) ($line['discountAmount'] ?? 0), 0);
    }

    public static function isValidType(?string $type): bool
    {
        return in_array($type, [self::PRODUCT, self::BRAND, self::CATEGORY], true);
    }
}

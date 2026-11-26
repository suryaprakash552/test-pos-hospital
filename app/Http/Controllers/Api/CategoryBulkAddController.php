<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * The items of a category, prepared for adding to a cart in one tap.
 *
 * ## Why the quantities are decided here
 *
 * The client could fetch the category's products and work out quantities itself, but
 * then two places would decide how much stock exists — and the app getting that wrong
 * is exactly what made items unsellable before. The server owns availability, so it
 * owns the quantity each line may carry.
 *
 * Each line comes back with the quantity to add, already capped at what is actually
 * in stock. A product with nothing available is returned as unavailable rather than
 * omitted, so the app can say *why* an item was skipped instead of silently dropping
 * it from a bulk add.
 */
class CategoryBulkAddController extends Controller
{
    public function __invoke(Request $request, Category $category)
    {
        $user = auth()->user();

        if ($category->business_id !== $user->business_id) {
            return response()->json(['message' => __('Unauthorized.')], 403);
        }

        if (! $category->bulk_add_enabled) {
            return response()->json([
                'message' => __('This category is not set up for bulk add.'),
                'reason'  => 'bulk_add_disabled',
            ], 409);
        }

        // The caller may ask for a different quantity than the category default —
        // clamped, so a client cannot request a negative or absurd amount.
        $requested = $request->filled('quantity')
            ? max(1, (int) $request->integer('quantity'))
            : (int) $category->default_quantity;

        $minimum = (int) $category->min_order_quantity;

        $products = Product::where('business_id', $user->business_id)
            ->where('category_id', $category->id)
            ->with([
                'stocks' => fn ($q) => $q
                    ->withSum('availableLots as available_quantity', 'conversionQuantity'),
            ])
            ->get();

        $lines = [];
        $skipped = [];

        foreach ($products as $product) {
            $available = (float) $product->stocks->sum(
                fn ($stock) => (float) ($stock->available_quantity ?? 0)
            );

            if ($available <= 0) {
                // Reported, not dropped: a bulk add that quietly skips half a
                // category leaves the user with a wrong order and no explanation.
                $skipped[] = [
                    'product_id'   => $product->id,
                    'product_name' => $product->productName,
                    'reason'       => 'out_of_stock',
                ];
                continue;
            }

            // Never more than exists. The cart is capped for the same reason the
            // POS is: offering a quantity the sale endpoint will reject wastes the
            // cashier's time at the counter.
            $quantity = min($requested, $available);

            if ($minimum > 0 && $quantity < $minimum) {
                $skipped[] = [
                    'product_id'       => $product->id,
                    'product_name'     => $product->productName,
                    'reason'           => 'below_minimum',
                    'available'        => $available,
                    'minimum_quantity' => $minimum,
                ];
                continue;
            }

            $lines[] = [
                'product_id'         => $product->id,
                'product_name'       => $product->productName,
                'product_code'       => $product->productCode,
                'quantity'           => $quantity,
                'available_quantity' => $available,
                // Flagged so the app can tell the user it added fewer than asked.
                'capped'             => $quantity < $requested,
                'min_order_quantity' => $minimum,
            ];
        }

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => [
                'category_id'        => $category->id,
                'category_name'      => $category->categoryName,
                'requested_quantity' => $requested,
                'min_order_quantity' => $minimum,
                'lines'              => $lines,
                'skipped'            => $skipped,
                'total_products'     => $products->count(),
            ],
        ]);
    }
}

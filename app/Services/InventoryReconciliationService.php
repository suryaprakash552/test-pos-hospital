<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compares the lot ledger with legacy upper-unit tally columns.
 *
 * PurchaseDetails::conversionQuantity remains the source of truth. This class
 * never derives a lot balance from `productStock`; it only reports or, after an
 * explicit operator request, repairs those redundant tally columns.
 */
class InventoryReconciliationService
{
    /** @return Collection<int, array<string,mixed>> */
    public function inspect(?int $businessId = null, ?int $branchId = null): Collection
    {
        $stocks = Stock::query()
            ->withoutGlobalScopes()
            ->with(['purchaseDetails:id,stock_id,conversionQuantity,conversionValue', 'product:id,business_id,productStock'])
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('business_id')
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();

        $rows = $stocks->map(function (Stock $stock): array {
            $lower = round((float) $stock->purchaseDetails->sum('conversionQuantity'), 4);
            // A product can have lots with different pack sizes. Convert every
            // lot independently; dividing their combined lower units by one
            // batch/product conversion would create false drift.
            $expectedUpper = round((float) $stock->purchaseDetails->sum(
                fn ($lot) => (float) $lot->conversionQuantity / max(1, (float) $lot->conversionValue)
            ), 4);
            $storedUpper = round((float) $stock->productStock, 4);

            return [
                'business_id' => (int) $stock->business_id,
                'branch_id' => $stock->branch_id === null ? null : (int) $stock->branch_id,
                'product_id' => (int) $stock->product_id,
                'product_name' => $stock->product?->productName,
                'stock_id' => (int) $stock->id,
                'batch_no' => $stock->batch_no,
                'authoritative_lower_quantity' => $lower,
                'expected_batch_quantity' => $expectedUpper,
                'stored_batch_quantity' => $storedUpper,
                'batch_difference' => round($storedUpper - $expectedUpper, 4),
                'batch_mismatch' => abs($storedUpper - $expectedUpper) > 0.0001,
                'negative_or_impossible' => $lower < 0 || $storedUpper < 0,
            ];
        });

        $products = Product::query()
            ->withoutGlobalScopes()
            ->when($businessId, fn ($query) => $query->where('business_id', $businessId))
            ->get(['id', 'business_id', 'productName', 'productStock'])
            ->keyBy(fn (Product $product) => $product->business_id.':'.$product->id);
        $productExpected = $rows->groupBy(fn (array $row) => $row['business_id'].':'.$row['product_id'])
            ->map(fn (Collection $group) => round((float) $group->sum('expected_batch_quantity'), 4));

        // A master product can retain a stale legacy tally even after all of its
        // stock rows are gone. Include it as a product-only row in the full
        // reconciliation; there is no batch row to compare in that case.
        if ($branchId === null) {
            foreach ($products as $key => $product) {
                if ($productExpected->has($key)) {
                    continue;
                }
                $rows->push([
                    'business_id' => (int) $product->business_id,
                    'branch_id' => null,
                    'product_id' => (int) $product->id,
                    'product_name' => $product->productName,
                    'stock_id' => null,
                    'batch_no' => null,
                    'authoritative_lower_quantity' => 0.0,
                    'expected_batch_quantity' => 0.0,
                    'stored_batch_quantity' => 0.0,
                    'batch_difference' => 0.0,
                    'batch_mismatch' => false,
                    'negative_or_impossible' => false,
                ]);
                $productExpected->put($key, 0.0);
            }
        }

        return $rows->map(function (array $row) use ($productExpected, $products, $branchId): array {
            $product = $products->get($row['business_id'].':'.$row['product_id']);
            $expected = (float) $productExpected[$row['business_id'].':'.$row['product_id']];
            $stored = (float) ($product?->productStock ?? 0);

            $row['expected_product_quantity'] = $expected;
            $row['stored_product_quantity'] = round($stored, 4);
            $row['product_difference'] = round($stored - $expected, 4);
            // Product tally spans every branch. A branch-restricted command can
            // safely reconcile each batch, but cannot compare that global tally
            // against a partial branch slice.
            $row['product_mismatch'] = $branchId === null && abs($stored - $expected) > 0.0001;

            return $row;
        });
    }

    /** @param Collection<int, array<string,mixed>> $rows */
    public function apply(Collection $rows): int
    {
        $correlationId = 'reconcile-'.Str::uuid();
        $changed = 0;

        DB::transaction(function () use ($rows, $correlationId, &$changed): void {
            foreach ($rows->where('batch_mismatch', true) as $row) {
                $stock = Stock::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($row['stock_id']);
                $before = ['stored_upper_quantity' => (float) $stock->productStock];
                $stock->update(['productStock' => $row['expected_batch_quantity']]);
                app(InventoryChangeService::class)->recordDerivedTallyCorrection(
                    $stock,
                    $before,
                    ['stored_upper_quantity' => (float) $row['expected_batch_quantity'], 'authoritative_lower_quantity' => $row['authoritative_lower_quantity']],
                    $correlationId,
                );
                $changed++;
            }

            foreach ($rows->groupBy(fn (array $row) => $row['business_id'].':'.$row['product_id']) as $group) {
                $row = $group->first();
                if (! $row['product_mismatch']) {
                    continue;
                }
                $product = Product::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($row['product_id']);
                $before = ['stored_upper_quantity' => (float) $product->productStock];
                $product->update(['productStock' => $row['expected_product_quantity']]);
                app(InventoryChangeService::class)->recordDerivedTallyCorrection(
                    $product,
                    $before,
                    ['stored_upper_quantity' => (float) $row['expected_product_quantity']],
                    $correlationId,
                );
                $changed++;
            }

            app(InventoryChangeService::class)->invalidateInventoryAfterCommit();
        });

        return $changed;
    }
}

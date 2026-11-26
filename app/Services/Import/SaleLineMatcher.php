<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\Stock;

/**
 * Matches imported products and records available batch hints. The cart
 * preparation endpoint checks current stock and allocates earliest-expiry
 * batches automatically, splitting quantities across batches when needed.
 * Product ambiguity still requires employee confirmation.
 */
class SaleLineMatcher
{
    use ProductNameMatching;

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> the line attributes, ready to persist
     */
    public function match(int $businessId, array $row): array
    {
        $name = ImportProductIdentifier::isPresent($row['product_name'] ?? null)
            ? trim((string) $row['product_name']) : '';
        $code = ImportProductIdentifier::isPresent($row['product_code'] ?? null)
            ? trim((string) $row['product_code']) : '';
        $batchNo = $this->text($row['batch_no'] ?? null);

        $line = [
            'product_name' => $name !== '' ? $name : null,
            'product_code' => $code !== '' ? $code : null,
            'batch_no' => $batchNo,
            // A name-only pick list means one unit unless a quantity is supplied.
            'quantity' => $this->number($row['quantity'] ?? null) ?? 1,
            // A sales bill's rate column is this row's selling price, read
            // the same way a purchase bill's rate column is read.
            'price' => $this->number($row['purchase_price'] ?? $row['price'] ?? null),
            'discount_percent' => $this->number($row['discount_percent'] ?? null),
            'tax_percent' => $this->number($row['tax_percent'] ?? null),
        ];

        $resolved = $line + $this->resolveProduct($businessId, $name, $code);

        if ($resolved['product_id'] === null) {
            return $resolved + ['stock_id' => null];
        }

        return $this->resolveStock($businessId, $resolved, (int) $resolved['product_id'], $batchNo);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function resolveStock(int $businessId, array $line, int $productId, ?string $batchNo): array
    {
        $product = Product::query()->where('business_id', $businessId)->find($productId, ['id']);

        if (! $product) {
            return array_merge($line, ['stock_id' => null]);
        }

        $sellable = Stock::query()
            ->where('business_id', $businessId)
            ->where('product_id', $productId)
            ->where(function ($query) {
                $query->whereNull('expire_date')->orWhereDate('expire_date', '>=', now()->toDateString());
            })
            ->withSum('availableLots as available_quantity', 'conversionQuantity')
            ->get(['id', 'batch_no'])
            ->filter(fn (Stock $stock) => $stock->available_quantity > 0)
            ->values();

        // The bill named a batch, and this shop holds one by that name: use
        // it, whether or not it happens to be the only one with stock. A
        // batch a supplier's bill names is more specific evidence than a
        // count of how many others exist.
        if ($batchNo !== null) {
            $named = $sellable->first(
                fn (Stock $stock) => mb_strtolower(trim((string) $stock->batch_no)) === mb_strtolower($batchNo)
            );

            if ($named) {
                return array_merge($line, [
                    'stock_id' => $named->id,
                    'match_status' => 'matched',
                    'note' => null,
                ]);
            }
        }

        if ($sellable->count() === 1) {
            return array_merge($line, [
                'stock_id' => $sellable->first()->id,
                'match_status' => 'matched',
                'note' => null,
            ]);
        }

        if ($sellable->isEmpty()) {
            return array_merge($line, [
                'stock_id' => null,
                'match_status' => 'unmatched',
                'note' => 'This product has no stock to sell from right now.',
            ]);
        }

        return array_merge($line, [
            'stock_id' => null,
            'match_status' => 'ambiguous',
            'note' => 'This product has '.$sellable->count().' batches in stock. Stock will be allocated automatically, earliest expiry first.',
        ]);
    }
}

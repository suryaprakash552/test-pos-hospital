<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetails;
use App\Models\Stock;
use App\Services\BatchConsumption;
use App\Services\Inventory\MovementContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Creates one real sale from a spreadsheet of lines.
 *
 * ## Why this is stricter than the purchase importer
 *
 * A purchase *adds* stock, so a rejected row costs nothing. A sale *consumes* it, and
 * a spreadsheet can consume a hundred batches in one go. Two rules follow:
 *
 * **All or nothing.** If any line cannot be fulfilled the whole import is abandoned.
 * A half-imported sale is an invoice that under-bills the customer while over-consuming
 * stock, and nothing on the document says which half is missing.
 *
 * **Consumption goes through `BatchConsumption`** — the same code the till uses. Writing
 * stock down by hand here would be a second implementation of FIFO, and the two drifting
 * apart is precisely what made items unsellable while showing stock.
 *
 * Batches are chosen nearest-expiry-first among those with stock, matching what the POS
 * offers, so an imported sale consumes the same lots a counter sale would.
 *
 * Expected columns:
 *   0 product code   1 quantity   2 unit price (optional)   3 batch no (optional)
 */
class SaleImport implements ToCollection, SkipsEmptyRows
{
    /** @var string[] */
    protected array $errors = [];

    protected int $imported = 0;
    protected ?int $saleId = null;

    public function __construct(
        protected int $businessId,
        protected int $userId,
        protected ?int $customerId = null,
        protected ?string $saleDate = null,
        protected ?string $invoiceNumber = null,
    ) {
    }

    public function collection(Collection $rows): void
    {
        $rows = $rows->slice(1); // header

        DB::transaction(function () use ($rows) {
            $lines = [];

            // The sale is opened before any stock moves so that each lot drawn
            // can name the document it was drawn for. Consuming first left the
            // movements with nothing to attribute them to, so an imported sale
            // had no allocation to reverse and no lineage to audit. The total
            // is settled once the rows are known; a failure anywhere rolls the
            // whole thing back, this row included.
            $sale = Sale::create([
                'business_id'   => $this->businessId,
                'user_id'       => $this->userId,
                'customer_id'   => $this->customerId,
                'invoiceNumber' => $this->invoiceNumber ?: 'IMP-S-' . now()->format('YmdHis'),
                'saleDate'      => $this->saleDate ?: now()->toDateString(),
                'totalAmount'   => 0,
                'paidAmount'    => 0,
                'dueAmount'     => 0,
            ]);

            $movement = MovementContext::forSource(
                $sale,
                'sale_import',
                'sale-'.$sale->id,
                'Sale imported',
            );

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                $productCode = trim((string) ($row[0] ?? ''));
                $quantity    = (float) ($row[1] ?? 0);

                if ($productCode === '') {
                    continue;
                }

                if ($quantity <= 0) {
                    $this->fail("Row {$rowNumber}: quantity must be greater than zero.");
                }

                $product = Product::where('business_id', $this->businessId)
                    ->whereRaw("TRIM(LEADING '0' FROM productCode) = ?", [ltrim($productCode, '0')])
                    ->first();

                if (! $product) {
                    $this->fail("Row {$rowNumber}: no product with code '{$productCode}'.");
                }

                $batchNo = trim((string) ($row[3] ?? '')) ?: null;
                $stock = $this->stockFor($product, $batchNo, $rowNumber);

                // Refuses before consuming anything, so a shortfall found on row 40
                // leaves rows 1-39 untouched.
                if (! BatchConsumption::consume($stock->id, $quantity, $movement)) {
                    $available = BatchConsumption::available($stock->id);

                    $this->fail(
                        "Row {$rowNumber}: {$product->productName} has only {$available} "
                        . "available, needed {$quantity}."
                    );
                }

                $price = (isset($row[2]) && is_numeric($row[2]) && $row[2] !== '')
                    ? (float) $row[2]
                    : (float) ($stock->productSalePrice ?: $product->productSalePrice);

                $conversionValue = (float) ($stock->conversionValue ?: 1);

                // Mirrors the till: productStock is kept in upper units.
                $stock->decrement(
                    'productStock',
                    $quantity / ($conversionValue > 0 ? $conversionValue : 1)
                );

                $lines[] = [
                    'stock_id'   => $stock->id,
                    'product_id' => $product->id,
                    'price'      => $price,
                    'quantities' => $quantity,
                    'lossProfit' => ($price - (float) $stock->productPurchasePrice) * $quantity,
                ];
            }

            if ($lines === []) {
                $this->fail('Nothing to import: no valid rows found.');
            }

            $total = array_sum(array_map(
                fn (array $line) => $line['price'] * $line['quantities'],
                $lines,
            ));

            // Imported sales are unsettled until paid; recording them as paid
            // would silently move the shop balance.
            $sale->update([
                'totalAmount' => $total,
                'dueAmount'   => $total,
            ]);

            SaleDetails::insert(array_map(
                fn (array $line) => $line + ['sale_id' => $sale->id],
                $lines,
            ));

            $this->saleId = $sale->id;
            $this->imported = count($lines);
        });
    }

    /**
     * The batch to sell from.
     *
     * A named batch is honoured exactly, so a sheet can direct stock deliberately.
     * Otherwise the nearest expiry with stock is used — what the POS offers — so an
     * imported sale consumes the same lots a counter sale would.
     */
    private function stockFor(Product $product, ?string $batchNo, int $rowNumber): Stock
    {
        $query = Stock::where('business_id', $this->businessId)
            ->where('product_id', $product->id);

        if ($batchNo !== null) {
            $stock = (clone $query)->where('batch_no', $batchNo)->first();

            if (! $stock) {
                $this->fail("Row {$rowNumber}: batch '{$batchNo}' not found for {$product->productName}.");
            }

            return $stock;
        }

        $stock = $query->get()
            ->filter(fn (Stock $candidate) => BatchConsumption::available($candidate->id) > 0)
            ->sortBy(fn (Stock $candidate) => $candidate->expire_date ?? '9999-12-31')
            ->first();

        if (! $stock) {
            $this->fail("Row {$rowNumber}: {$product->productName} has no stock available.");
        }

        return $stock;
    }

    /**
     * Aborts the import.
     *
     * Throwing rolls the transaction back, which is the point: every line already
     * consumed is returned, so a failure leaves stock exactly as it was.
     */
    private function fail(string $message): never
    {
        $this->errors[] = $message;

        throw new \RuntimeException($message);
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    public function importedCount(): int
    {
        return $this->imported;
    }

    public function saleId(): ?int
    {
        return $this->saleId;
    }
}

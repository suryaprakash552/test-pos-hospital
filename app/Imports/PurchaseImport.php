<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Stock;
use App\Support\Api\PurchaseLineBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Creates one real purchase from a spreadsheet of lines.
 *
 * ## Why it goes through PurchaseLineBuilder
 *
 * Stock is not a column that can be written to directly. A purchase creates or tops up
 * a batch (`stocks`), records what was bought into `purchase_details`, and those detail
 * rows are what sales later draw down FIFO. Inserting rows by hand would produce
 * batches whose remaining quantity does not reconcile against sales — the same class of
 * corruption that made items unsellable while showing stock.
 *
 * So this builds the same payload the API builds and hands it to the same
 * `PurchaseLineBuilder` that `PurchaseController::store` uses. If the purchase rules
 * change, they change in one place.
 *
 * The whole file is one purchase in one transaction: a partly-imported delivery is
 * worse than a rejected one, because the missing half is invisible.
 *
 * Expected columns:
 *   0 product code   1 quantity        2 purchase price   3 sale price
 *   4 batch no       5 expire date     6 conversion value
 */
class PurchaseImport implements ToCollection, SkipsEmptyRows
{
    /** @var string[] Human-readable reasons rows were rejected. */
    protected array $errors = [];

    protected int $imported = 0;

    public function __construct(
        protected int $businessId,
        protected int $userId,
        protected ?int $partyId = null,
        protected ?string $purchaseDate = null,
        protected ?string $invoiceNumber = null,
    ) {
    }

    public function collection(Collection $rows): void
    {
        $rows = $rows->slice(1); // header

        $lines = [];

        foreach ($rows as $index => $row) {
            // +2: one for the header, one because spreadsheets are 1-indexed.
            $rowNumber = $index + 2;

            $productCode = trim((string) ($row[0] ?? ''));
            $quantity    = (float) ($row[1] ?? 0);

            if ($productCode === '') {
                continue;
            }

            if ($quantity <= 0) {
                $this->errors[] = "Row {$rowNumber}: quantity must be greater than zero.";
                continue;
            }

            // Leading zeros are lost when a spreadsheet treats a code as a number, so
            // both sides are trimmed before comparing.
            $product = Product::where('business_id', $this->businessId)
                ->whereRaw("TRIM(LEADING '0' FROM productCode) = ?", [ltrim($productCode, '0')])
                ->first();

            if (! $product) {
                $this->errors[] = "Row {$rowNumber}: no product with code '{$productCode}'.";
                continue;
            }

            $lines[] = $this->lineFor($product, $row, $quantity);
        }

        if ($lines === []) {
            $this->errors[] = 'Nothing to import: no valid rows found.';
            return;
        }

        DB::transaction(function () use ($lines) {
            $purchase = Purchase::create([
                'business_id'   => $this->businessId,
                'user_id'       => $this->userId,
                'party_id'      => $this->partyId,
                'invoiceNumber' => $this->invoiceNumber ?: 'IMP-' . now()->format('YmdHis'),
                'purchaseDate'  => $this->purchaseDate ?: now()->toDateString(),
                'totalAmount'   => collect($lines)->sum('total_purchase_price'),
                'paidAmount'    => 0,
                // Imported deliveries are unpaid until settled; recording them as paid
                // would silently move the shop balance.
                'dueAmount'     => collect($lines)->sum('total_purchase_price'),
            ]);

            $details = [];

            foreach ($lines as $line) {
                // The same builder the API uses, so an imported purchase and a typed
                // one produce identical stock.
                $stock = PurchaseLineBuilder::stockFor($line, $this->businessId);
                $details[] = PurchaseLineBuilder::detailPayload($line, $purchase->id, $stock->id);
            }

            PurchaseDetails::insert($details);

            foreach ($lines as $line) {
                $stock = Stock::where([
                    'batch_no'    => $line['batch_no'] ?? null,
                    'business_id' => $this->businessId,
                    'product_id'  => $line['product_id'],
                ])->lockForUpdate()->firstOrFail();

                $stock->increment('productStock', $line['quantities'] ?? 0);
            }

            $this->imported = count($lines);
        });
    }

    /**
     * Builds the line in the shape the API sends, falling back to the product's own
     * prices when the sheet leaves them blank.
     */
    private function lineFor(Product $product, $row, float $quantity): array
    {
        $numeric = static fn ($value, $fallback) =>
            ($value !== null && $value !== '' && is_numeric($value))
                ? (float) $value
                : (float) $fallback;

        $purchasePrice = $numeric($row[2] ?? null, $product->productPurchasePrice);
        $salePrice     = $numeric($row[3] ?? null, $product->productSalePrice);
        $batchNo       = trim((string) ($row[4] ?? '')) ?: null;
        $expireDate    = $this->parseExcelDate($row[5] ?? null);

        // Falls back to the product's own conversion value, which is frozen once the
        // product has been transacted — so an import cannot reinterpret history.
        $conversionValue = $numeric($row[6] ?? null, $product->conversionValue ?: 1);
        $conversionValue = $conversionValue > 0 ? $conversionValue : 1;

        return [
            'product_id'              => $product->id,
            'quantities'              => $quantity,
            'batch_no'                => $batchNo,
            'expire_date'             => $expireDate,
            'mfg_date'                => null,
            'productPurchasePrice'    => $purchasePrice,
            'productSalePrice'        => $salePrice,
            'productDealerPrice'      => (float) ($product->productDealerPrice ?: $salePrice),
            'productWholeSalePrice'   => (float) ($product->productWholeSalePrice ?: $salePrice),
            'conversion_purchase_price' => $conversionValue > 0
                ? $purchasePrice / $conversionValue
                : $purchasePrice,
            'conversion_quantities'   => $quantity * $conversionValue,
            'conversion_value'        => $conversionValue,
            'conversion_price'        => $conversionValue > 0
                ? $salePrice / $conversionValue
                : $salePrice,
            'total_purchase_price'    => $quantity * $purchasePrice,
            'total_price'             => $quantity * $salePrice,
            'profit_percent'          => (float) ($product->profit_percent ?? 0),
            'upper_selected_unit'     => $product->productUpperUnit,
            'lower_selected_unit'     => $product->productLowerUnit,
        ];
    }

    private function parseExcelDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // A date-formatted cell arrives as an Excel serial number.
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
            }

            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
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
}

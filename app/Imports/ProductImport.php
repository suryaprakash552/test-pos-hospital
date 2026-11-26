<?php

namespace App\Imports;

use Carbon\Carbon;
use App\Models\Vat;
use App\Models\Unit;
use App\Models\Brand;
use App\Models\Stock;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductModel;
use App\Models\Business;
use App\Services\GstConfiguration;
use App\Services\GstPricingMethod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ProductImport implements ToCollection, SkipsEmptyRows, WithChunkReading
{
    protected $businessId;
    protected ?int $forcedCategoryId = null;
    protected $categories = [];
    protected $brands = [];
    protected $units = [];
    protected $vats = [];
    protected $models = [];
    protected $productsToInsert = [];
    protected $existingProductCodes = [];
    protected $excelProductCodes = [];

    /** Rows the sheet got wrong, reported rather than silently dropped. */
    protected array $errors = [];

    protected int $imported = 0;

    /**
     * §15: what a row with no tax-applicability column inherits.
     *
     * Resolved once at construction rather than per row: it is a property of the
     * shop, and re-reading it thousands of times would be the same answer each time.
     */
    protected string $branchMethod;

    /**
     * @param int|null $forcedCategoryId When the upload was started from a category,
     *                                   every row is filed under it and the
     *                                   spreadsheet's category column is ignored.
     */
    public function __construct($businessId, ?int $forcedCategoryId = null)
    {
        $this->businessId = $businessId;
        $this->forcedCategoryId = $forcedCategoryId;

        $this->existingProductCodes = Product::where('business_id', $businessId)
            ->pluck('productCode')
            ->toArray();

        $this->branchMethod = GstConfiguration::resolve(
            null,
            null,
            Business::with('category:id,default_vat_type')->find($businessId),
        );
    }

    public function collection(Collection $rows)
    {
        DB::transaction(function () use ($rows) {
            foreach ($rows as $index => $row) {
                if ($index === 0 && strtolower(trim((string) $row[0])) === 'product name') {
                    continue;
                }

                // Read Excel fields
                $productName = trim($row[0]);
                $categoryName = trim($row[1]);
                $unitName = trim($row[2]);
                $brandName = trim($row[3]);
                $stockQty = $row[4] ?? 0;
                $productCode = trim($row[5]);
                $purchasePrice = (float)($row[6] ?? 0);
                $salePrice = (float)($row[7] ?? 0);
                $dealerPrice = (float)($row[8] ?? $salePrice);
                $wholesalePrice = (float)($row[9] ?? $salePrice);
                $vatName = trim($row[10]);
                $rawVatPercent = $row[11] ?? 0;
                $vatPercent = (float) $rawVatPercent;
                // §15: a blank tax-applicability column inherits the branch method
                // rather than assuming exclusive, which silently mispriced every
                // imported product for an inclusive-pricing shop.
                $vatType = blank($row[12] ?? null)
                    ? $this->branchMethod
                    : trim((string) $row[12]);
                $alertQty = (int)($row[13] ?? 0);
                $expireDate = $this->parseExcelDate($row[14]);
                $batchNo = $row[15] ?? null;
                $model = trim($row[16]);

                $manufacturingDate = $row[17] ?? null;

                // Appended rather than inserted, so an existing template keeps
                // working — a sheet without these columns simply leaves them empty.
                $hsnCode = trim((string) ($row[18] ?? ''));
                $cessRate = (float) ($row[19] ?? 0);

                if (!$productName || !$productCode) {
                    continue;
                }

                // Row numbers are 1-based and the header is row 1, matching what the
                // user sees in the spreadsheet.
                $rowNumber = $index + 1;

                // §15: invalid tax information is an error, not something to correct
                // silently. A wrong rate reaches a customer's invoice; a wrong HSN is
                // rejected at filing time, months later and far from this file.
                if (! blank($rawVatPercent) && (! is_numeric($rawVatPercent) || $vatPercent < 0 || $vatPercent > 100)) {
                    $this->errors[] = "Row {$rowNumber}: '{$rawVatPercent}' is not a valid GST rate.";
                    continue;
                }

                if (! GstPricingMethod::isValid($vatType)) {
                    $this->errors[] = "Row {$rowNumber}: '{$vatType}' is not a valid GST pricing method.";
                    continue;
                }

                if ($hsnCode !== '' && ! preg_match('/^[0-9]{4,8}$/', $hsnCode)) {
                    $this->errors[] = "Row {$rowNumber}: '{$hsnCode}' is not a valid HSN/SAC code.";
                    continue;
                }

                // The category column is only required when the upload was not
                // started from a category.
                if (!$this->forcedCategoryId && !$categoryName) {
                    continue;
                }

                // Skip duplicates
                if (in_array($productCode, $this->existingProductCodes)) continue;
                if (in_array($productCode, $this->excelProductCodes)) continue;

                // VAT & profit calculation. §6: a branch that is not registered
                // charges nothing, so a rate left in the sheet is not applied.
                $vatAmount = GstPricingMethod::chargesTax($vatType)
                    ? ($purchasePrice * $vatPercent) / 100
                    : 0.0;
                $grandPurchasePrice = $vatType === 'inclusive'
                    ? $purchasePrice + $vatAmount
                    : $purchasePrice;

                $profitPercent = $purchasePrice > 0
                    ? number_format((($salePrice - $purchasePrice) / $purchasePrice) * 100, 2)
                    : 0;

                $this->excelProductCodes[] = $productCode;

                // Create or get related IDs
                // A category chosen in the app wins over anything in the sheet, so
                // a mistyped name cannot scatter rows into new categories.
                $categoryId = $this->forcedCategoryId
                    ?? ($this->categories[$categoryName] ??= Category::firstOrCreate(
                        ['categoryName' => $categoryName, 'business_id' => $this->businessId],
                        ['categoryName' => $categoryName]
                    )->id);

                $brandId = $this->brands[$brandName] ??= Brand::firstOrCreate(
                    ['brandName' => $brandName, 'business_id' => $this->businessId],
                    ['brandName' => $brandName]
                )->id;

                $unitId = $this->units[$unitName] ??= Unit::firstOrCreate(
                    ['unitName' => $unitName, 'business_id' => $this->businessId],
                    ['unitName' => $unitName]
                )->id;

                $vatId = $this->vats[$vatName] ??= Vat::firstOrCreate(
                    ['name' => $vatName, 'business_id' => $this->businessId],
                    ['name' => $vatName, 'rate' => $vatPercent]
                )->id;

                $modelId = $this->models[$model] ??= ProductModel::firstOrCreate(
                    ['name' => $model, 'business_id' => $this->businessId],
                    ['name' => $model]
                )->id;

                // Create product
                $product = Product::create([
                    'productName' => $productName,
                    'business_id' => $this->businessId,
                    'unit_id' => $unitId,
                    'brand_id' => $brandId,
                    'category_id' => $categoryId,
                    'model_id' => $modelId,
                    'productCode' => $productCode,
                    'vat_id' => $vatId,
                    'vat_type' => $vatType,
                    'hsn_code' => $hsnCode === '' ? null : $hsnCode,
                    'cess_rate' => $cessRate,
                    'vat_amount' => $vatAmount,
                    'alert_qty' => $alertQty,
                    'expire_date' => $expireDate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->imported++;

                Stock::updateOrCreate(
                    [
                        'batch_no'    => $batchNo,
                        'business_id' => $this->businessId,
                        'product_id'  => $product->id,
                    ],
                    [
                        'expire_date' => $expireDate,
                        'productStock' => $stockQty,
                        'productPurchasePrice' => $grandPurchasePrice,
                        'profit_percent' => $profitPercent,
                        'productSalePrice' => $salePrice,
                        'productWholeSalePrice' => $wholesalePrice,
                        'productDealerPrice' => $dealerPrice,
                        'mfg_date' => $manufacturingDate,
                    ]
                );
            }
        });
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * Rows rejected for bad tax data, so the file can be corrected.
     *
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function importedCount(): int
    {
        return $this->imported;
    }

    function parseExcelDate($value)
    {
        if (empty($value)) {
            return null;
        }

        // If it is numeric (Excel timestamp)
        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        // Remove extra spaces
        $value = trim($value);

        // Try MM/DD/YYYY first
        try {
            return Carbon::createFromFormat('m/d/Y', $value)->format('Y-m-d');
        } catch (\Exception $e) {
            // Try DD/MM/YYYY
            try {
                return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
            } catch (\Exception $e2) {
                // Try default parse (YYYY-MM-DD etc.)
                try {
                    return Carbon::parse($value)->format('Y-m-d');
                } catch (\Exception $e3) {
                    return null;
                }
            }
        }
    }
}

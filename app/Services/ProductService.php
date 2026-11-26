<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use App\DTOs\ProductDTO;
use Illuminate\Support\Facades\DB;
use App\Helpers\HasUploader;

/**
 * NOT WIRED UP. Nothing references this class - the equivalent logic currently
 * lives in the controllers. Wire it up or delete it; editing it alone changes
 * no behaviour.
 */
class ProductService extends BaseService
{
    use HasUploader;

    /**
     * Get products with filtering and pagination.
     */
    public function getProducts(array $filters = [], int $perPage = 50)
    {
        $query = Product::with([
            'unit:id,unitName',
            'upperSelectedUnit:id,unitName',
            'lowerSelectedUnit:id,unitName',
            'vat:id,rate',
            'brand:id,brandName',
            'manufacturer:id,manufacturerName',
            'category:id,categoryName',
            'product_model:id,name',
            'stocks' => fn($q) => $q->latest(),
            'stocks.purchaseDetails' => fn($q) => $q->orderByDesc('id'),
        ])
        ->withSum('purchaseDetails as stock_quantity', 'conversionQuantity')
        ->where('business_id', $this->businessId());

        // Apply filters
        if (!empty($filters['product_name'])) {
            $query->where('productName', 'like', '%' . $filters['product_name'] . '%');
        }

        if (!empty($filters['barcode'])) {
            $query->where('productCode', $filters['barcode']);
        }

        if (!empty($filters['category_id'])) {
            $categoryIds = explode(',', $filters['category_id']);
            $query->whereIn('category_id', $categoryIds);
        }

        if (!empty($filters['rack'])) {
            $query->where('rack', 'like', '%' . $filters['rack'] . '%');
        }

        if (!empty($filters['shelf'])) {
            $query->where('shelf', 'like', '%' . $filters['shelf'] . '%');
        }

        if (!empty($filters['manufacturer_id'])) {
            $query->where('manufacturer_id', $filters['manufacturer_id']);
        }

        if (!empty($filters['model_id'])) {
            $query->where('model_id', $filters['model_id']);
        }

        $products = $query->latest()->paginate($perPage);

        // Calculate additional data
        $products->getCollection()->transform(function ($product) {
            $product->conversion_quantity = $product->stocks->sum(function ($stock) {
                return $stock->purchaseDetails->sum('conversionQuantity');
            });
            return $product;
        });

        return $products;
    }

    /**
     * Get total stock value for products.
     */
    public function getTotalStockValue($products): float
    {
        return $products->sum(function ($product) {
            return $product->stocks->sum(function ($stock) {
                return $stock->productPurchasePrice * $stock->productStock;
            });
        });
    }

    /**
     * Create a new product.
     */
    public function createProduct(ProductDTO $dto): Product
    {
        return $this->transaction(function () use ($dto) {
            $product = Product::create([
                'productName' => $dto->productName,
                'productDescription' => $dto->productDescription,
                'productCode' => $dto->productCode,
                'productPicture' => $dto->productPicture,
                'business_id' => $dto->business_id ?? $this->businessId(),
                'category_id' => $dto->category_id,
                'unit_id' => $dto->unit_id,
                'brand_id' => $dto->brand_id,
                'manufacturer_id' => $dto->manufacturer_id,
                'vat_id' => $dto->vat_id,
                'vat_type' => $dto->vat_type,
                'vat_amount' => $dto->vat_amount,
                'profit_percent' => $dto->profit_percent,
                'productDealerPrice' => $dto->productDealerPrice,
                'productPurchasePrice' => $dto->productPurchasePrice,
                'productSalePrice' => $dto->productSalePrice,
                'productWholeSalePrice' => $dto->productWholeSalePrice,
                'productStock' => $dto->productStock,
                'alert_qty' => $dto->alert_qty,
                'expire_date' => $dto->expire_date,
                'size' => $dto->size,
                'color' => $dto->color,
                'weight' => $dto->weight,
                'capacity' => $dto->capacity,
                'model_id' => $dto->model_id,
                'warehouse_id' => $dto->warehouse_id,
                'product_type' => $dto->product_type,
                'rack' => $dto->rack,
                'shelf' => $dto->shelf,
                'productUpperUnit' => $dto->productUpperUnit,
                'productLowerUnit' => $dto->productLowerUnit,
                'conversionValue' => $dto->conversionValue,
                'product_flat_discount' => $dto->product_flat_discount,
                'product_percent_discount' => $dto->product_percent_discount,
                'meta' => $dto->meta,
            ]);

            return $product;
        });
    }

    /**
     * Update an existing product.
     */
    public function updateProduct(Product $product, ProductDTO $dto): Product
    {
        return $this->transaction(function () use ($product, $dto) {
            $product->update([
                'productName' => $dto->productName,
                'productDescription' => $dto->productDescription,
                'productCode' => $dto->productCode,
                'productPicture' => $dto->productPicture,
                'category_id' => $dto->category_id,
                'unit_id' => $dto->unit_id,
                'brand_id' => $dto->brand_id,
                'manufacturer_id' => $dto->manufacturer_id,
                'vat_id' => $dto->vat_id,
                'vat_type' => $dto->vat_type,
                'vat_amount' => $dto->vat_amount,
                'profit_percent' => $dto->profit_percent,
                'productDealerPrice' => $dto->productDealerPrice,
                'productPurchasePrice' => $dto->productPurchasePrice,
                'productSalePrice' => $dto->productSalePrice,
                'productWholeSalePrice' => $dto->productWholeSalePrice,
                'productStock' => $dto->productStock,
                'alert_qty' => $dto->alert_qty,
                'expire_date' => $dto->expire_date,
                'size' => $dto->size,
                'color' => $dto->color,
                'weight' => $dto->weight,
                'capacity' => $dto->capacity,
                'model_id' => $dto->model_id,
                'warehouse_id' => $dto->warehouse_id,
                'product_type' => $dto->product_type,
                'rack' => $dto->rack,
                'shelf' => $dto->shelf,
                'productUpperUnit' => $dto->productUpperUnit,
                'productLowerUnit' => $dto->productLowerUnit,
                'conversionValue' => $dto->conversionValue,
                'product_flat_discount' => $dto->product_flat_discount,
                'product_percent_discount' => $dto->product_percent_discount,
                'meta' => $dto->meta,
            ]);

            return $product->fresh();
        });
    }

    /**
     * Delete a product.
     */
    public function deleteProduct(Product $product): bool
    {
        return $this->transaction(function () use ($product) {
            // Delete product image
            if ($product->productPicture) {
                // Storage::delete($product->productPicture);
            }

            return $product->delete();
        });
    }

    /**
     * Get expired products.
     */
    public function getExpiredProducts()
    {
        return Product::where('business_id', $this->businessId())
            ->where('expire_date', '<', now())
            ->with(['category', 'unit', 'stocks'])
            ->get();
    }

    /**
     * Get products by location.
     */
    public function getProductsByLocation(array $filters = [])
    {
        $query = Product::where('business_id', $this->businessId());

        if (!empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (!empty($filters['rack'])) {
            $query->where('rack', $filters['rack']);
        }

        if (!empty($filters['shelf'])) {
            $query->where('shelf', $filters['shelf']);
        }

        return $query->with(['warehouse', 'stocks'])->get();
    }
}

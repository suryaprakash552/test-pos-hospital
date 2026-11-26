<?php

namespace App\DTOs;

class ProductDTO extends BaseDTO
{
    public string $productName;
    public ?string $productDescription;
    public ?string $productCode;
    public ?string $productPicture;
    public ?int $business_id;
    public ?int $category_id;
    public ?int $unit_id;
    public ?int $brand_id;
    public ?int $manufacturer_id;
    public ?int $vat_id;
    public ?string $vat_type;
    public ?float $vat_amount;
    public ?float $profit_percent;
    public ?float $productDealerPrice;
    public ?float $productPurchasePrice;
    public ?float $productSalePrice;
    public ?float $productWholeSalePrice;
    public ?float $productStock;
    public ?float $alert_qty;
    public ?string $expire_date;
    public ?string $size;
    public ?string $color;
    public ?string $weight;
    public ?string $capacity;
    public ?int $model_id;
    public ?int $warehouse_id;
    public ?string $product_type;
    public ?string $rack;
    public ?string $shelf;
    public ?int $productUpperUnit;
    public ?int $productLowerUnit;
    public ?float $conversionValue;
    public ?float $product_flat_discount;
    public ?float $product_percent_discount;
    public ?array $meta;

    public function __construct(array $data)
    {
        $this->productName = $data['productName'] ?? '';
        $this->productDescription = $data['productDescription'] ?? null;
        $this->productCode = $data['productCode'] ?? null;
        $this->productPicture = $data['productPicture'] ?? null;
        $this->business_id = $data['business_id'] ?? null;
        $this->category_id = $data['category_id'] ?? null;
        $this->unit_id = $data['unit_id'] ?? null;
        $this->brand_id = $data['brand_id'] ?? null;
        $this->manufacturer_id = $data['manufacturer_id'] ?? null;
        $this->vat_id = $data['vat_id'] ?? null;
        $this->vat_type = $data['vat_type'] ?? null;
        $this->vat_amount = isset($data['vat_amount']) ? (float) $data['vat_amount'] : null;
        $this->profit_percent = isset($data['profit_percent']) ? (float) $data['profit_percent'] : null;
        $this->productDealerPrice = isset($data['productDealerPrice']) ? (float) $data['productDealerPrice'] : null;
        $this->productPurchasePrice = isset($data['productPurchasePrice']) ? (float) $data['productPurchasePrice'] : null;
        $this->productSalePrice = isset($data['productSalePrice']) ? (float) $data['productSalePrice'] : null;
        $this->productWholeSalePrice = isset($data['productWholeSalePrice']) ? (float) $data['productWholeSalePrice'] : null;
        $this->productStock = isset($data['productStock']) ? (float) $data['productStock'] : null;
        $this->alert_qty = isset($data['alert_qty']) ? (float) $data['alert_qty'] : null;
        $this->expire_date = $data['expire_date'] ?? null;
        $this->size = $data['size'] ?? null;
        $this->color = $data['color'] ?? null;
        $this->weight = $data['weight'] ?? null;
        $this->capacity = $data['capacity'] ?? null;
        $this->model_id = $data['model_id'] ?? null;
        $this->warehouse_id = $data['warehouse_id'] ?? null;
        $this->product_type = $data['product_type'] ?? null;
        $this->rack = $data['rack'] ?? null;
        $this->shelf = $data['shelf'] ?? null;
        $this->productUpperUnit = $data['productUpperUnit'] ?? null;
        $this->productLowerUnit = $data['productLowerUnit'] ?? null;
        $this->conversionValue = isset($data['conversionValue']) ? (float) $data['conversionValue'] : null;
        $this->product_flat_discount = isset($data['product_flat_discount']) ? (float) $data['product_flat_discount'] : null;
        $this->product_percent_discount = isset($data['product_percent_discount']) ? (float) $data['product_percent_discount'] : null;
        $this->meta = $data['meta'] ?? null;
    }
}

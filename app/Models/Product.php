<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type',
        'productName',
        'business_id',
        'productDescription',
        'unit_id',
        'brand_id',
        'manufacturer_id',
        'vat_id',
        'vat_type',
        'vat_amount',
        'hsn_code',
        'cess_rate',
        'gst_exemption_reason',
        'profit_percent',
        'category_id',
        'productCode',
        'productPicture',
        'productDealerPrice',
        'productPurchasePrice',
        'productSalePrice',
        'productWholeSalePrice',
        'productStock',
        'alert_qty',
        'expire_date',
        'size',
        'meta',
        'color',
        'weight',
        'capacity',
        'model_id',
        'warehouse_id',
        'product_type',
        'rack',
        'shelf',
        'productUpperUnit',
        'productLowerUnit',
        'conversionValue',
        'product_flat_discount',
        'product_percent_discount'
    ];

    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function manufacturer()
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    // public function rack(): BelongsTo
    // {
    //     return $this->belongsTo(Rack::class);
    // }

    // public function shelf(): BelongsTo
    // {
    //     return $this->belongsTo(Shelf::class);
    // }

        public function upperSelectedUnit()
    {
        return $this->belongsTo(Unit::class, 'productUpperUnit', 'id');
    }

    public function lowerSelectedUnit()
    {
        return $this->belongsTo(Unit::class, 'productLowerUnit', 'id');
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function product_model()
    {
        return $this->belongsTo(ProductModel::class, 'model_id', 'id');
    }

    public function vat()
    {
        return $this->belongsTo(Vat::class);
    }

    public function batch()
    {
        return $this->hasOne(Stock::class)
            ->where('productStock', '>', 0)
            ->latestOfMany();
    }

    public function purchaseDetails()
{
    return $this->hasManyThrough(
        PurchaseDetails::class,
        Stock::class,
        'product_id', // Foreign key on stocks table
        'stock_id',   // Foreign key on purchase_details table
        'id',         // Local key on products table
        'id'          // Local key on stocks table
    );
}

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'model_id' => 'integer',
        'warehouse_id' => 'integer',
        'business_id' => 'integer',
        'unit_id' => 'integer',
        'brand_id' => 'integer',
        'manufacturer_id' => 'integer',
        'vat_id' => 'integer',
        'category_id' => 'integer',
        'productDealerPrice' => 'decimal:2',
        'productPurchasePrice' => 'decimal:2',
        'productSalePrice' => 'decimal:2',
        'productWholeSalePrice' => 'decimal:2',
        'productStock' => 'decimal:2',
        'productUpperUnit' => 'integer',
        'productLowerUnit' => 'integer',
        'conversionValue' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'profit_percent' => 'decimal:2',
        'alert_qty' => 'decimal:2',
        'product_flat_discount' => 'decimal:2',
        'product_percent_discount' => 'decimal:2',
        'stocks_sum_product_stock' => 'decimal:2',
        'meta' => 'json',
        'expire_date' => 'date',
    ];
}

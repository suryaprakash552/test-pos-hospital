<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseDetails extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'purchase_id',
        'product_id',
        'productDealerPrice',
        'productPurchasePrice',
        'conversionPurchasePrice',
        'conversionQuantity',
        'conversionValue',
        'conversionPrice',
        'profit_percent',
        'productSalePrice',
        'productWholeSalePrice',
        'quantities',
        'free_quantity',
        'purchaseUpperLimit',
        'purchaseLowerLimit',
        'totalPrice',
        'totalPurchasePrice',
        'stock_id',
        'mfg_date',
        'expire_date',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function upperSelectedUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchaseUpperLimit');
    }

    public function lowerSelectedUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'purchaseLowerLimit');
    }

    protected $casts = [
        'tax_rate_snapshot' => 'double',
        'tax_inclusive_snapshot' => 'boolean',
        'stock_id' => 'integer',
        'purchase_id' => 'integer',
        'product_id' => 'integer',
        'productDealerPrice' => 'double',
        'productPurchasePrice' => 'double',
        'conversionPurchasePrice' => 'double',
        'conversionQuantity' => 'double',
        'conversionValue' => 'double',
        'conversionPrice' => 'double',
        'productSalePrice' => 'double',
        'productWholeSalePrice' => 'double',
        'quantities' => 'double',
        'free_quantity' => 'double',
        'totalPurchasePrice' => 'double',
        'totalPrice' => 'double',
        'profit_percent' => 'double',
    ];
}

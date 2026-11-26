<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One batch on a disposal document.
 *
 * `product_name`, `batch_no` and `expire_date` are copies taken at the moment
 * of disposal, not joins. What the shop reported destroying must not change
 * because someone renamed a product afterwards.
 */
class StockDisposalLine extends Model
{
    protected $fillable = [
        'stock_disposal_id',
        'business_id',
        'product_id',
        'stock_id',
        'product_name',
        'batch_no',
        'expire_date',
        'quantity',
        'unit_cost',
        'value',
    ];

    protected $casts = [
        'stock_disposal_id' => 'integer',
        'business_id' => 'integer',
        'product_id' => 'integer',
        'stock_id' => 'integer',
        'expire_date' => 'date',
        'quantity' => 'double',
        'unit_cost' => 'double',
        'value' => 'double',
    ];

    public function disposal(): BelongsTo
    {
        return $this->belongsTo(StockDisposal::class, 'stock_disposal_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

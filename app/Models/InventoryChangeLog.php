<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit events for controlled inventory source-document changes.
 *
 * The model intentionally contains no balance logic.  A correction creates a
 * new event; it never rewrites a past event.
 */
class InventoryChangeLog extends Model
{
    protected $fillable = [
        'business_id', 'branch_id', 'user_id', 'event_type', 'source_type',
        'source_id', 'purchase_id', 'purchase_detail_id', 'stock_id',
        'product_id', 'correlation_id', 'quantity_delta', 'before_values',
        'after_values', 'reason', 'ip_address',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'source_id' => 'integer',
        'purchase_id' => 'integer',
        'purchase_detail_id' => 'integer',
        'stock_id' => 'integer',
        'product_id' => 'integer',
        'quantity_delta' => 'float',
        'before_values' => 'array',
        'after_values' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Inventory change history is immutable.'));
        static::deleting(fn () => throw new LogicException('Inventory change history cannot be deleted.'));
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

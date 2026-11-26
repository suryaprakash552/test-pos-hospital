<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line read off a sales bill, before anyone has agreed it is right.
 *
 * [raw] keeps exactly what the parser saw and is never overwritten by an
 * edit, for the same reason PurchaseImportLine keeps it: when a layout is
 * misread, it is the only record of what the document actually said.
 *
 * Unlike a purchase line, this one also carries [stock_id] — a sale draws
 * down a batch that already exists rather than creating one, so matching a
 * product is only half the question; which of its batches to sell from is
 * the other half.
 */
class SaleImportLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_import_batch_id',
        'row_number',
        'raw',
        'product_id',
        'stock_id',
        'match_status',
        'product_name',
        'product_code',
        'batch_no',
        'quantity',
        'price',
        'discount_percent',
        'tax_percent',
        'note',
    ];

    protected $casts = [
        'raw' => 'array',
        'row_number' => 'integer',
        'quantity' => 'float',
        'price' => 'float',
        'discount_percent' => 'float',
        'tax_percent' => 'float',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SaleImportBatch::class, 'sale_import_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * Whether this line can go into a sale.
     *
     * A line needs a product, a specific batch to sell from, and a positive
     * quantity. A product match with no batch decided is exactly the state
     * more than one candidate batch leaves a row in — the reviewer has to
     * pick one, the same as picking a product for an unmatched row.
     */
    public function isCommittable(): bool
    {
        if ($this->match_status === 'skipped') {
            return false;
        }

        return $this->product_id !== null
            && $this->stock_id !== null
            && (float) $this->quantity > 0;
    }
}

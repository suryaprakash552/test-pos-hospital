<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line read off a supplier bill, before anyone has agreed it is right.
 *
 * [raw] keeps exactly what the parser saw, and is never overwritten when the
 * user edits the parsed fields. When a supplier's layout is misread — which is
 * the normal case for OCR — it is the only record of what the document actually
 * said, and the difference between "the parser got it wrong" and "the bill was
 * wrong" is one somebody has to be able to settle later.
 */
class PurchaseImportLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_import_batch_id',
        'row_number',
        'raw',
        'product_id',
        'match_status',
        'product_name',
        'product_code',
        'batch_no',
        'quantity',
        'free_quantity',
        'purchase_price',
        'mrp',
        'discount_percent',
        'tax_percent',
        'expire_date',
        'mfg_date',
        'note',
    ];

    protected $casts = [
        'raw' => 'array',
        'row_number' => 'integer',
        'quantity' => 'float',
        'free_quantity' => 'float',
        'purchase_price' => 'float',
        'mrp' => 'float',
        'discount_percent' => 'float',
        'tax_percent' => 'float',
        'expire_date' => 'date',
        'mfg_date' => 'date',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PurchaseImportBatch::class, 'purchase_import_batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether this line can go into a purchase.
     *
     * A line needs a product and a positive quantity. Everything else has a
     * sensible default, but stock cannot be added to a product nobody has
     * chosen, and a zero-quantity line is a heading or a total the parser
     * mistook for goods.
     */
    public function isCommittable(): bool
    {
        if ($this->match_status === 'skipped') {
            return false;
        }

        return $this->product_id !== null && (float) $this->quantity > 0;
    }
}

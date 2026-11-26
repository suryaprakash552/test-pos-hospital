<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sales bill on its way in, before it becomes a sale.
 *
 * Mirrors PurchaseImportBatch: nothing here touches stock. The batch holds
 * the uploaded document and how far parsing got; the lines hold what was
 * read out of it. A sale is only created when someone has reviewed the
 * lines and confirmed them, and that confirmation goes through the
 * ordinary sale endpoint.
 */
class SaleImportBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'user_id',
        'branch_id',
        'customer_id',
        'source',
        'original_filename',
        'disk',
        'path',
        'status',
        'error',
        'total_rows',
        'matched_rows',
        'reference_invoice_no',
        'sale_date',
        'sale_id',
        'parsed_at',
        'committed_at',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'parsed_at' => 'datetime',
        'committed_at' => 'datetime',
        'total_rows' => 'integer',
        'matched_rows' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SaleImportLine::class)->orderBy('row_number');
    }

    /**
     * Restricts a query to one business.
     *
     * An import holds a customer's identity and a shop's stock positions, so
     * it is scoped on every read — including the ones addressed by id, where
     * a bare `findOrFail` would otherwise hand one shop another's document.
     */
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    public function isEditable(): bool
    {
        return $this->status === 'ready';
    }

    public function isCommitted(): bool
    {
        return $this->status === 'committed';
    }
}

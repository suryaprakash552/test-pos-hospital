<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One disposal document: expired stock taken off the shelf on a given day.
 *
 * Immutable once written. A correction is a new document, never an edit —
 * a register that can be rewritten is not evidence.
 */
class StockDisposal extends Model
{
    protected $fillable = [
        'business_id',
        'branch_id',
        'user_id',
        'disposal_no',
        'disposal_date',
        'reason',
        'note',
        'total_quantity',
        'total_value',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'branch_id' => 'integer',
        'user_id' => 'integer',
        'disposal_date' => 'date',
        'total_quantity' => 'double',
        'total_value' => 'double',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockDisposalLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('business_id', $businessId);
    }
}

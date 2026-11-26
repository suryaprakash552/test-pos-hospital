<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A record of a GST setting being changed.
 *
 * Kept separate from the row it describes so the history survives the current value
 * being overwritten — which is the entire point of it. A tax authority asking why an
 * invoice from March was billed inclusive needs the answer to still exist after the
 * shop switched to exclusive in July.
 *
 * Append-only by convention: nothing in the application updates or deletes these.
 */
class GstConfigurationHistory extends Model
{
    protected $fillable = [
        'business_id',
        'configurable_type',
        'configurable_id',
        'field',
        'old_value',
        'new_value',
        'reason',
        'effective_from',
        'changed_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
    ];

    /** The business or branch whose setting changed. */
    public function configurable(): MorphTo
    {
        return $this->morphTo();
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}

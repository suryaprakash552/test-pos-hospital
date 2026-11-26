<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    /**
     * Mirrors the column defaults, so a not-yet-persisted Category reports the same
     * values as one read back from the database. Without this a freshly built
     * instance answers 0 to `default_quantity`, which would silently add nothing.
     */
    protected $attributes = [
        'bulk_add_enabled'   => false,
        'default_quantity'   => 20,
        'min_order_quantity' => 0,
    ];

    protected $fillable = [
        'categoryName',
        'business_id',
        'variationCapacity',
        'variationColor',
        'variationSize',
        'variationType',
        'variationWeight',
        'icon',
        'status',
        // One-tap bulk add; see the migration for why each is admin-controlled.
        'bulk_add_enabled',
        'default_quantity',
        'min_order_quantity',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'business_id' => 'integer',
        'variationSize' => 'boolean',
        'variationColor' => 'boolean',
        'variationCapacity' => 'boolean',
        'variationType' => 'boolean',
        'variationWeight' => 'boolean',
        'status' => 'integer',
    ];
}

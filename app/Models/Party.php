<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Party extends Model
{
    use BelongsToBusiness, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type',
        'name',
        'email',
        'gstin',
        'phone',
        'relationship',
        'due',
        'image',
        'status',
        'address',
        'business_id',
        'credit_limit',
        'loyalty_points',
        'wallet',
        'opening_balance',
        'opening_balance_type',
        'billing_address',
        'shipping_address',
        'meta',
    ];


    /**
     * Stored upper case, whatever was typed.
     *
     * A GSTIN is case-insensitive — `29aaacr5055k1z5` is the same registration
     * as `29AAACR5055K1Z5` — and the validator upper-cases before matching, so
     * a lowercase one is accepted. Normalising here means the invoice prints it
     * the way the department writes it, and two records for the same buyer
     * cannot differ only by case.
     */
    public function setGstinAttribute($value): void
    {
        $trimmed = is_string($value) ? trim($value) : $value;

        $this->attributes['gstin'] = blank($trimmed)
            ? null
            : strtoupper($trimmed);
    }
    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function purchases_dues() : HasMany
    {
        return $this->hasMany(Purchase::class)->where('dueAmount', '>', 0);
    }

    public function dueCollect()
    {
        return $this->hasOne(DueCollect::class);
    }
    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'due' => 'double',
        'wallet' => 'double',
        'business_id' => 'integer',
        'status' => 'integer',
        'meta' => 'json',
        'credit_limit' => 'double',
        'loyalty_points' => 'double',
        'opening_balance' => 'double',
        'billing_address' => 'json',
        'shipping_address' => 'json',
        'account_number' => 'string',
        'upi_id' => 'string',
        'zip_code',
        'state',
        'city',
        'country', 
        'supplier_address',
        'supplier_zip_code',
        'supplier_city',
        'supplier_state',
        'supplier_country',
        'shipper_phone_number',
        'shipper_address',
        'shipper_lr_number',
        'shipper_lr_payment',
        'shipper_zip_code',
        'shipper_city',
        'shipper_state',
        'shipper_country',
    ];
}

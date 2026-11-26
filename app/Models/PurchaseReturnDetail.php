<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseReturnDetail extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'business_id',
        'purchase_return_id',
        'purchase_detail_id',
        'return_amount',
        'return_qty',
        'free_return_qty',
        'return_discount',
        'total_return',
        'non_returnable',
        'return_cash',
        'return_upi',
        'return_card',
    ];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }
    public function purchaseDetail()
    {
        return $this->belongsTo(PurchaseDetails::class, 'purchase_detail_id');
    }

    protected $casts = [
        'business_id' => 'integer',
        'purchase_return_id' => 'integer',
        'purchase_detail_id' => 'integer',
        'return_amount' => 'double',
        'return_qty' => 'double',
        'free_return_qty' => 'double',
        'return_discount' => 'double',
        'total_return' => 'double',
        'non_returnable' => 'double',
        'return_cash' => 'double',
        'return_upi' => 'double',
        'return_card' => 'double',
    ];
}

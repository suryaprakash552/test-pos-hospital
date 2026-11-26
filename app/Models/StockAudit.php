<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAudit extends Model
{
    use HasFactory;

    protected $fillable = [

        'business_id',
        'user_id',
        'audit_reference',

        'product_id',
        'stock_id',

        'batch_no',

        'previous_quantity',
        'updated_quantity',
        'difference_quantity',

        'price_per_unit',
        'difference_amount',

        'remarks'
    ];

    public function business()
    {
        return $this->belongsTo(Business::class,'business_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class,
            'product_id'
        );
    }

    public function stock()
    {
        return $this->belongsTo(Stock::class,'stock_id');
    }
}

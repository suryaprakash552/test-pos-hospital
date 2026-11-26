<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderManagement extends Model
{
    use HasFactory;

    protected $table = 'order_management';

    protected $fillable = [
        'business_id',
        'branch_id',
        'product_id',
        'product_name',
        'rack',
        'shelf',
        'lower_qty',
        'upper_qty',
        'from_date',
        'to_date'
    ];
}

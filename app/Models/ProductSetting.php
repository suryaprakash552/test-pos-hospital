<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductSetting extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'modules'
    ];

    protected $casts = [
        'modules' => 'json',
        'business_id' => 'integer',
        'branch_id' => 'integer'
    ];
}

<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Manufacturer extends Model
{
    use HasFactory;

    protected $fillable = [
        'manufacturerName',
        'description',
        'business_id',
        'icon',
        'status',
    ];

    protected $casts = [
        'business_id' => 'integer',
        'status' => 'integer',
    ];
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppDoctor extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'doctor_name',
        'specialisation',
        'qualification',
        'experience',
        'consultation_fee',
        'extra_charges',
        'op_charges',
        'image',
        'status',
    ];

    protected $casts = [
        'consultation_fee' => 'decimal:2',
        'extra_charges' => 'decimal:2',
        'op_charges' => 'decimal:2',
        'status' => 'integer',
    ];
}

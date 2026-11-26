<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpDetail extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'doctor_id',
        'customer_id',
        'reason',
        'appointment_time',
        'documents',
        'amount',
        'payment_type',
        'payment_status',
    ];

    protected $casts = [
        'appointment_time' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(AppDoctor::class, 'doctor_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientVisit extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'customer_id',
        'doctor_id',
        'reason',
        'visiting_datetime',
        'existing_disease',
        'medicine_used',
        'any_allergies',
        'emergency_contact',
        'medical_report',
        'location',
        'address_type',
        'door_no',
        'building_name',
        'area',
        'landmark',
        'instructions',
        'status',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(AppDoctor::class, 'doctor_id');
    }

    public function visitReason(): BelongsTo
    {
        return $this->belongsTo(VisitReason::class, 'reason');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id')->withDefault();
    }
}

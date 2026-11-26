<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    protected $fillable = [

        'business_id',

        'name',
        'qualification',
        'specialization',
        'register_no',
        'mci_no',
        'location',
    ];

    public function business()
    {
        return $this->belongsTo(
            Business::class,
            'business_id'
        );
    }
}
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentType extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'status',
    ];

    protected $casts =[
        'business_id' => 'integer',
        'status' => 'integer',
    ];

    public static function createBillingDefaults(int $businessId): void
    {
        foreach (['Cash', 'UPI', 'Card', 'Split'] as $name) {
            static::firstOrCreate(
                ['business_id' => $businessId, 'name' => $name],
                ['status' => 1],
            );
        }
    }
}

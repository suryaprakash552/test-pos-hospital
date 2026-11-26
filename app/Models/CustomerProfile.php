<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_user_id',
        'full_name',
        'address',
        'occupation',
        'alternative_phone',
        'monthly_income',
        'email',
        'image',
    ];

    protected $casts = [
        'monthly_income' => 'decimal:2',
        'customer_user_id' => 'integer',
    ];

    public function customerUser(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class);
    }
}

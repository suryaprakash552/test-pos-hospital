<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickAction extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'customer_user_id',
        'action_type',
    ];

    public function customerUser(): BelongsTo
    {
        return $this->belongsTo(CustomerUser::class, 'customer_user_id');
    }
}

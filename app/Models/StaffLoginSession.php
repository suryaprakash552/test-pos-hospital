<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffLoginSession extends Model
{
    protected $fillable = [

        'business_id',

        'branch_id',

        'user_id',

        'login_at',

        'logout_at',
    ];
}

<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToBusiness
{
    public function scopeForBusiness(Builder $query, int $businessId): Builder
    {
        return $query->where($query->getModel()->getTable() . '.business_id', $businessId);
    }

    public function scopeVisibleTo(Builder $query, User $user, string $userColumn = 'user_id'): Builder
    {
        $query->forBusiness((int) $user->business_id);

        if ($user->role === 'staff') {
            $query->where($query->getModel()->getTable() . '.' . $userColumn, $user->id);
        }

        return $query;
    }
}

<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SalePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->business_id !== null;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Sale $sale): bool
    {
        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        if ($user->role === 'shop-owner') {
            return $user->business_id === $sale->business_id;
        }

        if ($user->role === 'staff') {
            return $user->id === $sale->user_id;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        if ($user->role === 'shop-owner' || $user->role === 'staff') {
            return $user->hasPermission('sales-create');
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Sale $sale): bool
    {
        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        if ($user->role === 'shop-owner') {
            return $user->business_id === $sale->business_id && $user->hasPermission('sales-update');
        }

        if ($user->role === 'staff') {
            return $user->id === $sale->user_id && $user->hasPermission('sales-update');
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Sale $sale): bool
    {
        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        if ($user->role === 'shop-owner') {
            return $user->business_id === $sale->business_id && $user->hasPermission('sales-delete');
        }

        if ($user->role === 'staff') {
            return $user->id === $sale->user_id && $user->hasPermission('sales-delete');
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Sale $sale): bool
    {
        return $user->role === 'superadmin' || $user->role === 'admin';
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Sale $sale): bool
    {
        return $user->role === 'superadmin';
    }
}

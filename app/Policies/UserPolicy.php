<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->role === 'superadmin' || $user->role === 'admin';
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        // Users can view themselves
        if ($user->id === $model->id) {
            return true;
        }

        // Superadmin and admin can view all users
        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        // Shop owners can view their business users
        if ($user->role === 'shop-owner' && $user->business_id === $model->business_id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->role === 'superadmin' || $user->role === 'admin' || $user->role === 'shop-owner';
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        // Users can update themselves
        if ($user->id === $model->id) {
            return true;
        }

        // Cannot update superadmin
        if ($model->role === 'superadmin') {
            return false;
        }

        // Superadmin and admin can update all users (except superadmin)
        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        // Shop owners can update their business users
        if ($user->role === 'shop-owner' && $user->business_id === $model->business_id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Cannot delete self
        if ($user->id === $model->id) {
            return false;
        }

        // Cannot delete superadmin
        if ($model->role === 'superadmin') {
            return false;
        }

        // Superadmin and admin can delete users
        if ($user->role === 'superadmin' || $user->role === 'admin') {
            return true;
        }

        // Shop owners can delete their business users
        if ($user->role === 'shop-owner' && $user->business_id === $model->business_id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->role === 'superadmin' || $user->role === 'admin';
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return $user->role === 'superadmin';
    }
}

<?php

namespace App\Services;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

abstract class BaseService
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Get the authenticated user.
     *
     * @return \App\Models\User|null
     */
    protected function authUser()
    {
        return auth()->user();
    }

    /**
     * Get the business ID from authenticated user.
     *
     * @return int|null
     */
    protected function businessId()
    {
        return $this->authUser()?->business_id;
    }

    /**
     * Get the branch ID from authenticated user.
     *
     * @return int|null
     */
    protected function branchId()
    {
        return $this->authUser()?->active_branch_id;
    }

    /**
     * Check if user is shop owner.
     *
     * @return bool
     */
    protected function isShopOwner(): bool
    {
        return $this->authUser()?->role === 'shop-owner';
    }

    /**
     * Check if user is staff.
     *
     * @return bool
     */
    protected function isStaff(): bool
    {
        return $this->authUser()?->role === 'staff';
    }

    /**
     * Execute callback in database transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    protected function transaction(callable $callback)
    {
        return \Illuminate\Support\Facades\DB::transaction($callback);
    }
}

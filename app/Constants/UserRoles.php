<?php

namespace App\Constants;

class UserRoles
{
    public const SUPERADMIN = 'superadmin';
    public const ADMIN = 'admin';
    public const SHOP_OWNER = 'shop-owner';
    public const STAFF = 'staff';

    /**
     * Get all available roles.
     */
    public static function all(): array
    {
        return [
            self::SUPERADMIN,
            self::ADMIN,
            self::SHOP_OWNER,
            self::STAFF,
        ];
    }

    /**
     * Get roles allowed for API login.
     */
    public static function allowedForApiLogin(): array
    {
        return [
            self::SHOP_OWNER,
            self::STAFF,
        ];
    }

    /**
     * Check if role is allowed for API login.
     */
    public static function isAllowedForApiLogin(string $role): bool
    {
        return in_array($role, self::allowedForApiLogin());
    }
}

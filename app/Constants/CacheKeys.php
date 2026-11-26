<?php

namespace App\Constants;

class CacheKeys
{
    // Authentication
    public const OTP_SETTINGS = 'otp_settings';
    public const DEFAULT_CURRENCY = 'default_currency';
    public const USER_CURRENCY = 'user_currency_';
    public const BUSINESS_SETTINGS = 'business_settings_';

    // User
    public const USER_PERMISSIONS = 'user_permissions_';
    public const USER_ROLES = 'user_roles_';

    // Business
    public const BUSINESS_CONFIG = 'business_config_';
    public const BUSINESS_CATEGORIES = 'business_categories';

    // System
    public const EMAIL_VERIFICATION_SETTINGS = 'email_varification';

    /**
     * Generate user-specific cache key.
     */
    public static function user(string $key, int $userId): string
    {
        return $key . $userId;
    }

    /**
     * Generate business-specific cache key.
     */
    public static function business(string $key, int $businessId): string
    {
        return $key . $businessId;
    }
}

<?php

namespace App\Constants;

class OtpSettings
{
    public const STATUS_ON = 'on';
    public const STATUS_OFF = 'off';
    public const DEFAULT_VISIBILITY_TIME = 180; // 3 minutes in seconds
    public const DEFAULT_RESET_EXPIRY = 3600; // 1 hour in seconds
    public const OTP_LENGTH = 6;
    public const OTP_MIN = 100000;
    public const OTP_MAX = 999999;

    /**
     * Check if OTP verification is enabled.
     */
    public static function isEnabled(?string $status): bool
    {
        return $status === self::STATUS_ON;
    }

    /**
     * Get OTP visibility time in seconds.
     */
    public static function getVisibilityTime(?int $customTime = null): int
    {
        return $customTime ?? self::DEFAULT_VISIBILITY_TIME;
    }

    /**
     * Get OTP expiry time in seconds.
     */
    public static function getResetExpiry(?int $customTime = null): int
    {
        return $customTime ?? self::DEFAULT_RESET_EXPIRY;
    }

    /**
     * Generate random OTP code.
     */
    public static function generateCode(): int
    {
        return random_int(self::OTP_MIN, self::OTP_MAX);
    }
}

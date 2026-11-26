<?php

namespace App\Services;

use App\Models\User;
use App\Models\Currency;
use App\Models\UserCurrency;
use App\Models\StaffLoginSession;
use App\Constants\UserRoles;
use App\Constants\CacheKeys;
use App\Services\CacheService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    protected CacheService $cacheService;
    protected LoggingService $loggingService;

    public function __construct(CacheService $cacheService, LoggingService $loggingService)
    {
        $this->cacheService = $cacheService;
        $this->loggingService = $loggingService;
    }

    /**
     * Create API token for user with expiration.
     */
    public function createToken(
        User $user,
        string $tokenName = 'api-token',
        ?string $deviceId = null,
        ?string $deviceName = null,
    ): string
    {
        $accessToken = $user->createToken($tokenName);
        if ($accessToken->accessToken) {
            $accessToken->accessToken->forceFill([
                'device_id' => $deviceId,
                'device_name' => $deviceName,
            ])->save();
        }
        $duration = Config::get('sanctum.expiration');

        if (is_numeric($duration) && (int) $duration > 0 && $accessToken->accessToken) {
            $accessToken->accessToken->expires_at = now()->addMinutes((int) $duration);
            $accessToken->accessToken->save();
        }

        return $accessToken->plainTextToken;
    }

    /**
     * Get currency for user (cached).
     */
    public function getUserCurrency(?int $businessId = null): Currency|UserCurrency|null
    {
        if (!$businessId) {
            return $this->getDefaultCurrency();
        }

        return $this->cacheService->remember(
            CacheKeys::user(CacheKeys::USER_CURRENCY, $businessId),
            function () use ($businessId) {
                return UserCurrency::select('id', 'name', 'code', 'symbol', 'position')
                    ->where('business_id', $businessId)
                    ->first();
            },
            3600,
            [CacheService::TAG_CURRENCIES]
        ) ?? $this->getDefaultCurrency();
    }

    /**
     * Get default currency (cached).
     */
    public function getDefaultCurrency(): ?Currency
    {
        return $this->cacheService->remember(
            CacheKeys::DEFAULT_CURRENCY,
            function () {
                return Currency::select('id', 'name', 'code', 'symbol', 'position')
                    ->where('is_default', 1)
                    ->first();
            },
            3600,
            [CacheService::TAG_CURRENCIES]
        );
    }

    /**
     * Handle staff logout.
     */
    public function handleStaffLogout(User $user): void
    {
        if ($user->role === UserRoles::STAFF) {
            $user->update(['last_logout_at' => now()]);

            StaffLoginSession::where('user_id', $user->id)
                ->whereNull('logout_at')
                ->latest()
                ->first()
                ?->update(['logout_at' => now()]);
        }
    }

    /**
     * Logout all users (admin only).
     */
    public function logoutAllUsers(): void
    {
        StaffLoginSession::whereNull('logout_at')->update(['logout_at' => now()]);
        
        \Laravel\Sanctum\PersonalAccessToken::query()->delete();

        $this->loggingService->logBusinessEvent('all_users_logged_out', [
            'performed_by' => auth()->id(),
        ]);
    }

    /**
     * Refresh user token.
     */
    public function refreshToken(User $user): string
    {
        $currentToken = $user->currentAccessToken();
        $deviceId = $currentToken?->device_id;
        $deviceName = $currentToken?->device_name;

        if ($currentToken) {
            $currentToken->delete();
        }

        // Refreshing credentials is still the same physical session. Keeping
        // its identity prevents a known till/tablet becoming an anonymous
        // extra device in login history or consuming another device slot.
        return $this->createToken($user, 'api-token', $deviceId, $deviceName);
    }

    /**
     * Check if user role is allowed for API login.
     */
    public function isAllowedForApiLogin(string $role): bool
    {
        return UserRoles::isAllowedForApiLogin($role);
    }

    /**
     * Get OTP settings (cached).
     */
    public function getOtpSettings(): array
    {
        return $this->cacheService->remember(
            CacheKeys::EMAIL_VERIFICATION_SETTINGS,
            function () {
                return get_option('email-varification');
            },
            3600,
            [CacheService::TAG_SETTINGS]
        );
    }

    /**
     * Clear user authentication cache.
     */
    public function clearUserCache(int $userId): void
    {
        $this->cacheService->clearUserCache($userId);
    }

    /**
     * Check if mail is configured.
     */
    public function mailIsConfigured(): bool
    {
        return !empty(config('mail.username')) && !empty(config('mail.from.address'));
    }

    /**
     * Check if mail queuing is enabled.
     */
    public function mailQueueEnabled(): bool
    {
        return config('pos.notifications.queue_mail', false);
    }
}

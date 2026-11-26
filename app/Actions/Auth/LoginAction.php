<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\Branch;
use App\Models\UserCurrency;
use App\Models\Currency;
use App\Models\StaffLoginSession;
use App\Constants\UserRoles;
use App\Services\LoggingService;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\NewAccessToken;

class LoginAction
{
    public const DEVICE_LIMIT = 5;
    protected LoggingService $loggingService;
    protected AuthService $authService;

    public function __construct(LoggingService $loggingService, AuthService $authService)
    {
        $this->loggingService = $loggingService;
        $this->authService = $authService;
    }

    /**
     * Execute login action.
     */
    public function execute(User $user, ?string $deviceId = null, ?string $deviceName = null): array
    {
        // Check if user role is allowed for API login
        if (!UserRoles::isAllowedForApiLogin($user->role)) {
            $this->loggingService->logAuthFailure([
                'email' => $user->email,
                'reason' => 'Role not allowed for API login',
                'role' => $user->role,
            ]);

            return [
                'success' => false,
                'message' => __('auth.role_not_allowed'),
                'status' => 406,
            ];
        }

        // Validate branch and business status
        $validation = $this->validateBusinessAndBranch($user);
        if (!$validation['valid']) {
            Auth::logout();
            return $validation;
        }

        // Check if email verification is pending
        if ($user->remember_token && !$user->business_id) {
            return [
                'success' => false,
                'message' => __('auth.verification_required'),
                'status' => 201,
                'requires_otp' => true,
            ];
        }

        // Get currency
        $currency = $this->getCurrency($user);

        // A device replaces its own old token, but never evicts another till.
        // Locking the user makes two simultaneous sixth-device logins serialize
        // instead of both observing four sessions and creating a sixth.
        $token = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $deviceId, $deviceName) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $tokens = $user->tokens()
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });

            if ($deviceId !== null && $deviceId !== '') {
                (clone $tokens)->where('device_id', $deviceId)->delete();
            }

            // The same handset, signing in again, takes its own slot back.
            //
            // The app's device id is generated once and kept in app storage, so
            // reinstalling it, clearing its data or restoring a backup produces
            // a *new* id for the same physical device. It then occupied a second
            // slot, and a shop with one counter phone and one tablet was told it
            // had reached three devices — with the same handset listed twice.
            //
            // Matching on the name it reports ("vivo I2223") reclaims that slot.
            // Two identical models on one account do collide, and the loser is
            // signed out rather than refused; on an account that is already
            // capped at three, being signed out and back in beats being locked
            // out with a list you cannot tell apart.
            if ($deviceName !== null && trim($deviceName) !== '' && $deviceName !== 'Legacy device') {
                (clone $tokens)->where('device_name', $deviceName)->delete();
            }

            if ((clone $tokens)->count() >= self::DEVICE_LIMIT) {
                return null;
            }

            return $this->authService->createToken(
                $user,
                'api-token',
                $deviceId,
                $deviceName,
            );
        });

        if ($token === null) {
            return [
                'success' => false,
                'message' => __('This account is already active on five devices. Ask an administrator to sign out an unused device and try again.'),
                'status' => 409,
            ];
        }

        // Record a staff session only after a device token was actually issued;
        // a blocked sixth device is not a login and must not pollute reports.
        if ($user->role === UserRoles::STAFF) {
            $user->update(['last_login_at' => now()]);
            StaffLoginSession::create([
                'business_id' => $user->business_id,
                'branch_id' => $user->branch_id,
                'user_id' => $user->id,
                'login_at' => now(),
            ]);
        }

        $this->loggingService->logBusinessEvent('user_login', [
            'user_id' => $user->id,
            'role' => $user->role,
            'business_id' => $user->business_id,
        ]);

        return [
            'success' => true,
            'message' => __('auth.login_success'),
            'data' => [
                'is_setup' => $user->business_id ? true : false,
                'token' => $token,
                'role' => $user->role,
                'business' => $user->business_id,
                'branch' => $user->branch_id,
                'currency' => $currency,
            ],
        ];
    }

    /**
     * Validate business and branch status.
     */
    protected function validateBusinessAndBranch(User $user): array
    {
        $business = $user->business;
        $branch = Branch::find($user->branch_id);

        if (!$branch && $user->branch_id) {
            return [
                'valid' => false,
                'message' => __('auth.branch_deleted'),
                'status' => 406,
            ];
        }

        // Multi-branch validation
        if (multibranch_active() && branch_count()) {
            if ($branch && !$branch->status && $user->branch_id && !$branch->is_main) {
                return [
                    'valid' => false,
                    'message' => __('auth.branch_inactive'),
                    'status' => 406,
                ];
            }
        } elseif (!multibranch_active()) {
            if ($user->active_branch_id) {
                $user->update(['active_branch_id' => null]);
            } elseif ($user->branch_id && !$branch->is_main) {
                return [
                    'valid' => false,
                    'message' => __('auth.multibranch_not_allowed'),
                    'status' => 406,
                ];
            }
        }

        // Business validation
        if ($business && !$business->status) {
            return [
                'valid' => false,
                'message' => __('auth.business_inactive'),
                'status' => 406,
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get currency for user.
     */
    protected function getCurrency(User $user): Currency|UserCurrency|null
    {
        return $this->authService->getUserCurrency($user->business_id);
    }
}

<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Models\Currency;
use App\Constants\OtpSettings;
use App\Services\LoggingService;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;

class VerifyOtpAction
{
    protected LoggingService $loggingService;
    protected AuthService $authService;

    public function __construct(LoggingService $loggingService, AuthService $authService)
    {
        $this->loggingService = $loggingService;
        $this->authService = $authService;
    }

    /**
     * Execute OTP verification action.
     */
    public function execute(string $email, int $otp): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => __('auth.user_not_found'),
                'status' => 404,
            ];
        }

        // Verify OTP
        if ($user->remember_token != $otp) {
            $this->loggingService->logSecurityEvent('otp_verification_failed', [
                'email' => $email,
                'user_id' => $user->id,
                'reason' => 'Invalid OTP',
            ]);

            return [
                'success' => false,
                'message' => __('auth.invalid_otp'),
                'status' => 404,
            ];
        }

        // Check OTP expiration
        if ($user->email_verified_at <= now()) {
            return [
                'success' => false,
                'message' => __('auth.otp_expired'),
                'status' => 400,
            ];
        }

        // Login user
        Auth::login($user);
        $isSetup = $user->business_id ? true : false;
        $token = $this->authService->createToken($user);

        // Update user verification status
        $user->update([
            'is_verified' => 1,
            'remember_token' => null,
            'email_verified_at' => now(),
        ]);

        // Get currency
        $currency = Currency::select('id', 'name', 'code', 'symbol', 'position')
            ->where('is_default', 1)
            ->first();

        $this->loggingService->logBusinessEvent('otp_verified', [
            'user_id' => $user->id,
            'email' => $email,
        ]);

        return [
            'success' => true,
            'message' => __('auth.login_success'),
            'data' => [
                'is_setup' => $isSetup,
                'token' => $token,
                'currency' => $currency,
            ],
        ];
    }
}

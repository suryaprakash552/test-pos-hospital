<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Constants\OtpSettings;
use App\Services\LoggingService;
use Illuminate\Support\Facades\Hash;

class ResetPasswordAction
{
    protected LoggingService $loggingService;

    public function __construct(LoggingService $loggingService)
    {
        $this->loggingService = $loggingService;
    }

    /**
     * Execute reset password action.
     */
    public function execute(string $email, int $code, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => __('auth.user_not_found'),
                'status' => 404,
            ];
        }

        if ($user->remember_token != $code) {
            $this->loggingService->logSecurityEvent('password_reset_failed', [
                'email' => $email,
                'user_id' => $user->id,
                'reason' => 'Invalid reset code',
            ]);

            return [
                'success' => false,
                'message' => __('auth.invalid_reset_code'),
                'status' => 404,
            ];
        }

        if ($user->email_verified_at <= now()) {
            return [
                'success' => false,
                'message' => __('auth.reset_code_expired'),
                'status' => 400,
            ];
        }

        $user->update([
            'password' => Hash::make($password),
            'remember_token' => null,
            'email_verified_at' => now(),
        ]);

        $this->loggingService->logBusinessEvent('password_reset', [
            'user_id' => $user->id,
            'email' => $email,
        ]);

        return [
            'success' => true,
            'message' => __('auth.password_reset_success'),
        ];
    }
}

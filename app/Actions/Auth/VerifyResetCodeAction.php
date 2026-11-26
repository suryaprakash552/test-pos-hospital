<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Constants\OtpSettings;
use App\Services\LoggingService;

class VerifyResetCodeAction
{
    protected LoggingService $loggingService;

    public function __construct(LoggingService $loggingService)
    {
        $this->loggingService = $loggingService;
    }

    /**
     * Execute verify reset code action.
     */
    public function execute(string $email, int $code): array
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
            $this->loggingService->logSecurityEvent('reset_code_verification_failed', [
                'email' => $email,
                'user_id' => $user->id,
                'reason' => 'Invalid code',
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

        $this->loggingService->logBusinessEvent('reset_code_verified', [
            'user_id' => $user->id,
            'email' => $email,
        ]);

        return [
            'success' => true,
            'message' => __('auth.reset_code_verified'),
        ];
    }
}

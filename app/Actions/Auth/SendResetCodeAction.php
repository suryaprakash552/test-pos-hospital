<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Constants\OtpSettings;
use App\Services\LoggingService;
use App\Services\AuthService;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordReset;

class SendResetCodeAction
{
    protected LoggingService $loggingService;
    protected AuthService $authService;

    public function __construct(LoggingService $loggingService, AuthService $authService)
    {
        $this->loggingService = $loggingService;
        $this->authService = $authService;
    }

    /**
     * Execute send reset code action.
     */
    public function execute(string $email): array
    {
        $user = User::where('email', $email)->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => __('auth.user_not_found'),
                'status' => 404,
            ];
        }

        $expire = now()->addSeconds(OtpSettings::getResetExpiry());
        $code = OtpSettings::generateCode();

        $user->update([
            'remember_token' => $code,
            'email_verified_at' => $expire,
        ]);

        try {
            $this->sendResetMail($user->email, $code);
        } catch (\Exception $exception) {
            $this->loggingService->logSystemFailure([
                'component' => 'password_reset_email',
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('Failed to send the reset code. Please try again later.'),
                'status' => 422,
            ];
        }

        $this->loggingService->logBusinessEvent('password_reset_code_sent', [
            'user_id' => $user->id,
            'email' => $email,
        ]);

        return [
            'success' => true,
            'message' => __('auth.reset_code_sent'),
        ];
    }

    /**
     * Send reset password email.
     */
    protected function sendResetMail(string $email, int $code): void
    {
        $data = ['code' => $code];

        if ($this->authService->mailQueueEnabled()) {
            Mail::to($email)->queue(new PasswordReset($data));
        } else {
            Mail::to($email)->send(new PasswordReset($data));
        }
    }
}

<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Constants\OtpSettings;
use App\Services\LoggingService;
use App\Services\AuthService;
use App\Jobs\SendOtpMailJob;
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\Mail;

class ResendOtpAction
{
    protected LoggingService $loggingService;
    protected AuthService $authService;

    public function __construct(LoggingService $loggingService, AuthService $authService)
    {
        $this->loggingService = $loggingService;
        $this->authService = $authService;
    }

    /**
     * Execute resend OTP action.
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

        $code = OtpSettings::generateCode();
        $visibilityTime = OtpSettings::getVisibilityTime();
        $expire = now()->addSeconds($visibilityTime);
        $name = $user->name ?? 'User';

        if (!$this->authService->mailIsConfigured()) {
            return [
                'success' => false,
                'message' => __('auth.mail_not_configured'),
                'status' => 406,
            ];
        }

        if ($this->authService->mailQueueEnabled()) {
            SendOtpMailJob::dispatch($email, $name, $code);
        } else {
            Mail::to($email)->send(new WelcomeMail(['code' => $code, 'name' => $name]));
        }

        $user->update([
            'remember_token' => $code,
            'email_verified_at' => $expire,
        ]);

        $this->loggingService->logBusinessEvent('otp_resent', [
            'user_id' => $user->id,
            'email' => $email,
        ]);

        return [
            'success' => true,
            'message' => __('auth.otp_sent'),
        ];
    }
}

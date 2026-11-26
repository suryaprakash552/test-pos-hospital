<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Constants\OtpSettings;
use App\Services\LoggingService;
use App\Services\AuthService;
use App\Jobs\SendOtpMailJob;
use App\Mail\WelcomeMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class SignUpAction
{
    protected LoggingService $loggingService;
    protected AuthService $authService;

    public function __construct(LoggingService $loggingService, AuthService $authService)
    {
        $this->loggingService = $loggingService;
        $this->authService = $authService;
    }

    /**
     * Execute sign up action.
     */
    public function execute(string $name, string $email, string $password): array
    {
        // Check if email already exists with business
        $existingUser = User::where('email', $email)->first();
        if ($existingUser && $existingUser->business_id) {
            return [
                'success' => false,
                'message' => __('auth.email_already_exists'),
                'status' => 406,
            ];
        }

        // Get OTP settings (cached)
        $otpSettings = $this->authService->getOtpSettings();
        $verifyEmail = OtpSettings::isEnabled($otpSettings['otp_status'] ?? null);

        $code = OtpSettings::generateCode();
        $visibilityTime = OtpSettings::getVisibilityTime();
        $expire = now()->addSeconds($visibilityTime);

        // Send OTP if verification is enabled
        if ($verifyEmail) {
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
        }

        // Create or update user within transaction
        $user = DB::transaction(function () use ($name, $email, $password, $verifyEmail, $code, $expire) {
            return User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'email' => $email,
                    'is_verified' => $verifyEmail ? 0 : 1,
                    'remember_token' => $verifyEmail ? $code : null,
                    'email_verified_at' => $verifyEmail ? $expire : null,
                    'password' => Hash::make($password),
                ]
            );
        });

        $this->loggingService->logBusinessEvent('user_signup', [
            'user_id' => $user->id,
            'email' => $email,
            'verified' => !$verifyEmail,
        ]);

        return [
            'success' => true,
            'message' => $verifyEmail ? __('auth.otp_sent') : __('auth.signup_complete'),
            'data' => [
                'token' => $verifyEmail ? null : $this->authService->createToken($user),
                'user' => $user->only(['id', 'name', 'email', 'role', 'business_id', 'branch_id', 'is_verified']),
                'requires_otp' => $verifyEmail,
            ],
        ];
    }
}

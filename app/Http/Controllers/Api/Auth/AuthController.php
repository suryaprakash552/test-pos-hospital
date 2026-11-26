<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\User;
use App\Models\Branch;
use App\Models\Currency;
use App\Mail\WelcomeMail;
use App\Models\UserCurrency;
use App\Models\StaffLoginSession;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Requests\Auth\SignUpRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\SubmitOtpRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\DTOs\Auth\SignUpDTO;
use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\SubmitOtpDTO;
use App\DTOs\Auth\ResendOtpDTO;
use App\Actions\Auth\SignUpAction;
use App\Actions\Auth\LoginAction;
use App\Actions\Auth\VerifyOtpAction;
use App\Actions\Auth\ResendOtpAction;
use App\Services\AuthService;
use App\Services\LoggingService;
use App\Http\Resources\Auth\UserResource;
use App\Http\Resources\Auth\CurrencyResource;
use App\Http\Resources\Auth\LoginResponseResource;
use App\Constants\UserRoles;
use App\Jobs\SendOtpMailJob;

class AuthController extends Controller
{
    protected AuthService $authService;
    protected LoggingService $loggingService;

    public function __construct(AuthService $authService, LoggingService $loggingService)
    {
        $this->authService = $authService;
        $this->loggingService = $loggingService;
    }

    public function otpSettings()
    {
        $otpSettings = $this->authService->getOtpSettings();

        return response()->json([
            'message' => 'Data fetched successfully.',
            'data' => $otpSettings
        ]);
    }

    public function signUp(SignUpRequest $request)
    {
        $dto = new SignUpDTO($request->validated());
        $action = new SignUpAction($this->loggingService, $this->authService);
        $result = $action->execute($dto->name, $dto->email, $dto->password);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'message' => $result['message'],
            'token' => $result['data']['token'],
            'data' => $result['data']['user'],
        ]);
    }

    public function submitOtp(SubmitOtpRequest $request)
    {
        $dto = new SubmitOtpDTO($request->validated());
        $action = new VerifyOtpAction($this->loggingService, $this->authService);
        $result = $action->execute($dto->email, $dto->otp);

        if (!$result['success']) {
            return response()->json([
                'error' => $result['message'],
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'message' => $result['message'],
            'is_setup' => $result['data']['is_setup'],
            'token' => $result['data']['token'],
            'currency' => $result['data']['currency'],
        ]);
    }

    public function login(LoginRequest $request)
    {
        if (RateLimiter::tooManyAttempts($request->throttleKey(), 5)) {
            return $this->loginLockedResponse($request);
        }

        $credentials = $request->only('email', 'password');

        if (!auth()->attempt($credentials)) {
            RateLimiter::hit($request->throttleKey(), 3 * 60 * 60);
            $this->loggingService->logAuthFailure([
                'email' => $request->email,
                'ip' => $request->ip(),
            ]);

            if (RateLimiter::tooManyAttempts($request->throttleKey(), 5)) {
                return $this->loginLockedResponse($request);
            }

            return response()->json(['message' => 'Invalid email or password!'], 404);
        }

        RateLimiter::clear($request->throttleKey());

        $user = auth()->user();
        $action = new LoginAction($this->loggingService, $this->authService);
        $deviceId = trim((string) $request->input('device_id', ''));
        if ($deviceId === '') {
            $deviceId = 'legacy:'.hash('sha256', $request->ip().'|'.$request->userAgent());
        }
        $deviceName = trim((string) $request->input('device_name', ''));
        if ($deviceName === '') {
            $deviceName = 'Legacy device';
        }
        $result = $action->execute($user, $deviceId, $deviceName);

        if (!$result['success']) {
            if (isset($result['requires_otp']) && $result['requires_otp']) {
                // Handle OTP resend for unverified users
                $code = \App\Constants\OtpSettings::generateCode();
                $expire = now()->addSeconds(\App\Constants\OtpSettings::getVisibilityTime());
                $name = $user->name ?? 'User';

                if (!$this->authService->mailIsConfigured()) {
                    return response()->json([
                        'message' => __('Mail service is not configured. Please contact your administrator.'),
                    ], 406);
                }

                if ($this->authService->mailQueueEnabled()) {
                    SendOtpMailJob::dispatch($request->email, $name, $code);
                } else {
                    Mail::to($request->email)->send(new WelcomeMail(['code' => $code, 'name' => $name]));
                }

                $user->update(['remember_token' => $code, 'email_verified_at' => $expire]);

                return response()->json([
                    'message' => 'An otp code has been sent to your email. Please check and confirm.',
                ], 201);
            }

            return response()->json([
                'message' => $result['message'],
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'message' => $result['message'],
            'data' => $result['data'],
        ]);
    }

    public function signOut(): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'message' => __('Unauthorized'),
            ], 401);
        }

        $currentToken = $user->currentAccessToken();

        if ($currentToken) {
            $this->authService->handleStaffLogout($user);
            $currentToken->delete();

            $this->loggingService->logBusinessEvent('user_logout', [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => __('Sign out successfully'),
            ]);
        }

        return response()->json([
            'message' => __('Unauthorized'),
        ], 401);
    }

    public function sessions(): JsonResponse
    {
        $user = auth()->user();
        $currentId = $user->currentAccessToken()?->id;

        $sessions = $user->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'device_id' => $token->device_id,
                'device_name' => $token->device_name
                    ?: (in_array($token->name, ['api-token', 'createToken'], true)
                        ? __('Legacy device #:id', ['id' => $token->id])
                        : $token->name),
                'login_at' => $token->created_at,
                'last_active_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'is_current' => $token->id === $currentId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(int $id): JsonResponse
    {
        $user = auth()->user();
        if (! $this->canManageDeviceSessions($user)) {
            return response()->json([
                'message' => __('Only an administrator can force logout a device.'),
            ], 403);
        }
        $token = $user->tokens()->whereKey($id)->firstOrFail();
        $wasCurrent = $user->currentAccessToken()?->id === $token->id;
        $token->delete();

        return response()->json([
            'message' => __('Device signed out successfully.'),
            'current_session_revoked' => $wasCurrent,
        ]);
    }

    public function logoutAllUsers()
    {
        $this->authService->logoutAllUsers();

        return response()->json([
            'message' => 'All users logged out successfully'
        ]);
    }

    public function refreshToken()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'message' => __('Unauthorized'),
            ], 401);
        }

        $token = $this->authService->refreshToken($user);

        return response()->json([
            'token' => $token,
        ]);
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        $dto = new ResendOtpDTO($request->validated());
        $action = new ResendOtpAction($this->loggingService, $this->authService);
        $result = $action->execute($dto->email);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function moduleCheck()
    {
        if (moduleCheck(request('module_name'))) {
            return response()->json([
                'status' => true
            ]);
        }

        return response()->json([
            'status' => false
        ]);
    }
    /**
     * The devices this account is signed in on.
     *
     * A fourth device is refused, and until now that was the end of it: the
     * login screen said "sign out one device" and offered no way to do it, from
     * the one screen where the person is by definition not signed in anywhere
     * they can reach the setting.
     *
     * So this is deliberately reachable without a token — but it is not
     * unauthenticated. The password is verified on every call, exactly as
     * signing in does, and the answer only ever concerns the account those
     * credentials belong to. Wrong credentials get the same refusal a wrong
     * login gets, and the same rate limit, so this adds no way to probe for
     * valid emails or passwords.
     *
     * Device names are the only detail returned. They are what a person needs
     * to recognise their own till; nothing about the session itself is exposed.
     */
    public function devices(LoginRequest $request)
    {
        $user = $this->userForCredentials($request);

        if ($user === null) {
            return response()->json(['message' => 'Invalid email or password!'], 404);
        }

        $devices = $user->tokens()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'device_name' => $token->device_name
                    ?: __('Legacy device #:id', ['id' => $token->id]),
                'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                'can_force_logout' => $this->canManageDeviceSessions($user),
            ]);

        return response()->json([
            'message' => __('Data fetched successfully.'),
            'data' => $devices,
        ]);
    }

    /**
     * Signs one of those devices out, so this one can sign in.
     *
     * Same credential check as [devices], and the token is looked up *through*
     * the authenticated user rather than by id alone — an id from another
     * account simply is not found, so this cannot be used to sign anyone else's
     * till out.
     */
    public function releaseDevice(LoginRequest $request)
    {
        $user = $this->userForCredentials($request);

        if ($user === null) {
            return response()->json(['message' => 'Invalid email or password!'], 404);
        }

        if (! $this->canManageDeviceSessions($user)) {
            return response()->json([
                'message' => __('Only an administrator can force logout a device.'),
            ], 403);
        }

        $request->validate(['token_id' => ['required', 'integer']]);

        $token = $user->tokens()->whereKey($request->input('token_id'))->first();

        if ($token === null) {
            return response()->json([
                'message' => __('That device is already signed out.'),
            ], 404);
        }

        $token->delete();

        $this->loggingService->logBusinessEvent('device_released', [
            'user_id' => $user->id,
            'business_id' => $user->business_id,
        ]);

        return response()->json([
            'message' => __('That device has been signed out. You can sign in now.'),
        ]);
    }

    /**
     * The account those credentials belong to, or null.
     *
     * Uses the same guard attempt the login does, so password hashing, timing
     * and the failure log all behave identically here.
     */
    private function userForCredentials(LoginRequest $request): ?User
    {
        if (! auth()->attempt($request->only('email', 'password'))) {
            $this->loggingService->logAuthFailure([
                'email' => $request->email,
                'ip' => $request->ip(),
            ]);

            return null;
        }

        return auth()->user();
    }

    private function canManageDeviceSessions(User $user): bool
    {
        return in_array($user->role, [
            UserRoles::SHOP_OWNER,
            UserRoles::ADMIN,
            UserRoles::SUPERADMIN,
        ], true);
    }

    private function loginLockedResponse(LoginRequest $request): JsonResponse
    {
        $seconds = RateLimiter::availableIn($request->throttleKey());

        return response()->json([
            'message' => __('Too many unsuccessful login attempts. Try again after three hours.'),
            'reason' => 'login_locked',
            'retry_after' => $seconds,
        ], 429);
    }
}

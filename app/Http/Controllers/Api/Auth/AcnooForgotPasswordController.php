<?php

namespace App\Http\Controllers\Api\Auth;

use App\Models\User;
use App\Mail\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Http\Requests\Auth\SendResetCodeRequest;
use App\Http\Requests\Auth\VerifyResetCodeRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\DTOs\Auth\SendResetCodeDTO;
use App\DTOs\Auth\VerifyResetCodeDTO;
use App\DTOs\Auth\ResetPasswordDTO;
use App\Actions\Auth\SendResetCodeAction;
use App\Actions\Auth\VerifyResetCodeAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Services\LoggingService;
use App\Services\AuthService;

class AcnooForgotPasswordController extends Controller
{
    protected LoggingService $loggingService;
    protected AuthService $authService;

    public function __construct(LoggingService $loggingService, AuthService $authService)
    {
        $this->loggingService = $loggingService;
        $this->authService = $authService;
    }

    public function sendResetCode(SendResetCodeRequest $request): JsonResponse
    {
        $dto = new SendResetCodeDTO($request->validated());
        $action = new SendResetCodeAction($this->loggingService, $this->authService);
        $result = $action->execute($dto->email);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function verifyResetCode(VerifyResetCodeRequest $request)
    {
        $dto = new VerifyResetCodeDTO($request->validated());
        $action = new VerifyResetCodeAction($this->loggingService);
        $result = $action->execute($dto->email, $dto->code);

        if (!$result['success']) {
            return response()->json([
                'error' => $result['message'],
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $dto = new ResetPasswordDTO($request->validated());
        $action = new ResetPasswordAction($this->loggingService);
        $result = $action->execute($dto->email, $dto->code, $dto->password);

        if (!$result['success']) {
            return response()->json([
                'error' => $result['message'],
            ], $result['status'] ?? 400);
        }

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}

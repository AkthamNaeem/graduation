<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ChangePasswordRequest;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\LogoutRequest;
use App\Http\Requests\Api\V1\Auth\MeRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Auth\PasswordResetOtpService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly PasswordResetOtpService $passwordResetOtpService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if ($result['status'] === AuthService::LOGIN_INVALID) {
            return ApiResponse::error(
                message: __('auth.failed'),
                errors: ['email' => [trans('auth.failed')]],
                status: 401,
            );
        }

        if ($result['status'] === AuthService::LOGIN_BLOCKED) {
            return ApiResponse::error(
                message: __('auth.inactive_support'),
                errors: ['status' => [__('auth.inactive')]],
                status: 403,
                code: 'USER_SUSPENDED',
            );
        }

        if ($result['status'] === AuthService::LOGIN_UNVERIFIED) {
            return ApiResponse::error(
                message: __('auth.email_verification_required'),
                errors: ['email' => [__('auth.verification_pending')]],
                status: 403,
                code: 'EMAIL_NOT_VERIFIED',
            );
        }

        return ApiResponse::success(
            data: [
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'user' => new UserResource($result['user']),
            ],
            message: __('auth.login_success'),
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwordResetOtpService->issueForEmail(
            $request->validated('email'),
        );

        return ApiResponse::success(
            data: $this->passwordResetOtpService->getMetadata(),
            message: __('auth.forgot_password'),
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->passwordResetOtpService->resetPassword(
            $data['email'],
            $data['otp'],
            $data['password'],
        );

        return ApiResponse::success(
            data: null,
            message: __('auth.password_reset'),
        );
    }

    public function me(MeRequest $request): JsonResponse
    {
        $user = $this->authService->loadAuthenticatedUser($request->user());

        return ApiResponse::success(
            data: new UserResource($user),
            message: __('auth.user_retrieved'),
        );
    }

    public function logout(LogoutRequest $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(
            data: null,
            message: __('auth.logout_success'),
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->authService->changePassword($request->user(), $data['current_password'], $data['password'])) {
            return ApiResponse::error(
                message: __('auth.current_password_incorrect_short'),
                errors: ['current_password' => [__('auth.current_password_incorrect')]],
                status: 422,
            );
        }

        return ApiResponse::success(
            data: null,
            message: __('auth.password_changed'),
        );
    }

    public function logoutAll(LogoutRequest $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());

        return ApiResponse::success(
            data: null,
            message: __('auth.logout_all_success'),
        );
    }
}

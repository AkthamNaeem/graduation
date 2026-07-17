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
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if ($result['status'] === AuthService::LOGIN_INVALID) {
            return ApiResponse::error(
                message: 'Invalid credentials.',
                errors: ['email' => [trans('auth.failed')]],
                status: 401,
            );
        }

        if ($result['status'] === AuthService::LOGIN_BLOCKED) {
            return ApiResponse::error(
                message: 'Your account is not active. Please contact support.',
                errors: ['status' => ['Only active users can login.']],
                status: 403,
                code: 'USER_SUSPENDED',
            );
        }

        return ApiResponse::success(
            data: [
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'user' => new UserResource($result['user']),
            ],
            message: 'Login successful.',
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authService->sendPasswordResetLink($request->validated());

        return ApiResponse::success(
            data: null,
            message: 'If an account with that email exists, a password reset link has been sent.',
        );
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        if (! $this->authService->resetPassword($request->validated())) {
            return ApiResponse::error(
                message: 'Invalid or expired password reset token.',
                errors: ['token' => ['The password reset token is invalid or has expired.']],
                status: 422,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'Password reset successfully.',
        );
    }

    public function me(MeRequest $request): JsonResponse
    {
        $user = $this->authService->loadAuthenticatedUser($request->user());

        return ApiResponse::success(
            data: new UserResource($user),
            message: 'Authenticated user retrieved successfully.',
        );
    }

    public function logout(LogoutRequest $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(
            data: null,
            message: 'Logout successful.',
        );
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $this->authService->changePassword($request->user(), $data['current_password'], $data['password'])) {
            return ApiResponse::error(
                message: 'Current password is incorrect.',
                errors: ['current_password' => ['The current password is incorrect.']],
                status: 422,
            );
        }

        return ApiResponse::success(
            data: null,
            message: 'Password changed successfully.',
        );
    }

    public function logoutAll(LogoutRequest $request): JsonResponse
    {
        $this->authService->logoutAll($request->user());

        return ApiResponse::success(
            data: null,
            message: 'Logged out from all devices successfully.',
        );
    }
}

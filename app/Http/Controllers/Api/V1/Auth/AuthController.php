<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\LogoutRequest;
use App\Http\Requests\Api\V1\Auth\MeRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        if (! $result) {
            return ApiResponse::error(
                message: 'Invalid credentials.',
                errors: ['email' => [trans('auth.failed')]],
                status: 401,
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
}

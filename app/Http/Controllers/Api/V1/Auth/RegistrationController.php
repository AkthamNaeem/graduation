<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\EmployerRegisterRequest;
use App\Http\Requests\Api\V1\Auth\JobSeekerRegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\EmailVerificationService;
use App\Services\Auth\RegistrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registrationService,
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    public function registerJobSeeker(JobSeekerRegisterRequest $request): JsonResponse
    {
        $user = $this->registrationService->registerJobSeeker($request->validated());

        return ApiResponse::success(
            data: [
                'user' => new UserResource($user),
                'email_verification' => $this->emailVerificationService->getVerificationMetadata(),
            ],
            message: 'Registration successful. Verify the account using the temporary OTP.',
            status: 201,
        );
    }

    public function registerEmployer(EmployerRegisterRequest $request): JsonResponse
    {
        $user = $this->registrationService->registerEmployer($request->validated());

        return ApiResponse::success(
            data: [
                'user' => new UserResource($user),
                'email_verification' => $this->emailVerificationService->getVerificationMetadata(),
            ],
            message: 'Registration successful. Verify the account using the temporary OTP.',
            status: 201,
        );
    }
}

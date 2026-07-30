<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ResendEmailOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyEmailOtpRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Auth\EmailVerificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
    ) {}

    public function verify(VerifyEmailOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $result = $this->emailVerificationService->verifyOtp($data['email'], $data['otp']);

        return ApiResponse::success(
            data: [
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'user' => new UserResource($result['user']),
            ],
            message: 'Email verified successfully.',
        );
    }

    public function resend(ResendEmailOtpRequest $request): JsonResponse
    {
        $this->emailVerificationService->reissueOtpForEmail(
            $request->validated('email'),
        );

        $metadata = $this->emailVerificationService->getVerificationMetadata();
        unset($metadata['required']);

        return ApiResponse::success(
            data: $metadata,
            message: 'If the account exists and requires verification, a new verification code is available.',
        );
    }
}

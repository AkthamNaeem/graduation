<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\ShowEmployerProfileRequest;
use App\Http\Requests\Api\V1\Profile\UpdateEmployerProfileRequest;
use App\Http\Resources\Api\V1\EmployerProfileResource;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployerProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {
    }

    public function show(ShowEmployerProfileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new EmployerProfileResource($this->profileService->getEmployerProfile($request->user())),
            message: 'Employer profile retrieved successfully.',
        );
    }

    public function update(UpdateEmployerProfileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new EmployerProfileResource($this->profileService->updateEmployerProfile($request->user(), $request->validated())),
            message: 'Employer profile updated successfully.',
        );
    }
}

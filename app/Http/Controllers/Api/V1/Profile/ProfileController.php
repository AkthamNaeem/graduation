<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\ShowProfileRequest;
use App\Http\Requests\Api\V1\Profile\UpdateJobSeekerProfileRequest;
use App\Http\Resources\Api\V1\JobSeekerProfileResource;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {
    }

    public function show(ShowProfileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new JobSeekerProfileResource($this->profileService->getJobSeekerProfile($request->user())),
            message: 'Profile retrieved successfully.',
        );
    }

    public function update(UpdateJobSeekerProfileRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new JobSeekerProfileResource($this->profileService->updateJobSeekerProfile($request->user(), $request->validated())),
            message: 'Profile updated successfully.',
        );
    }
}

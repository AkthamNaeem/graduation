<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\DestroyExperienceRequest;
use App\Http\Requests\Api\V1\Profile\ExperienceIndexRequest;
use App\Http\Requests\Api\V1\Profile\ShowExperienceRequest;
use App\Http\Requests\Api\V1\Profile\StoreExperienceRequest;
use App\Http\Requests\Api\V1\Profile\UpdateExperienceRequest;
use App\Http\Resources\Api\V1\ExperienceResource;
use App\Models\Experience;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ExperienceController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {
    }

    public function index(ExperienceIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: ExperienceResource::collection($this->profileService->getExperiences($request->user())),
            message: 'Experiences retrieved successfully.',
        );
    }

    public function store(StoreExperienceRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new ExperienceResource($this->profileService->createExperience($request->user(), $request->validated())),
            message: 'Experience created successfully.',
            status: 201,
        );
    }

    public function show(ShowExperienceRequest $request, Experience $experience): JsonResponse
    {
        return ApiResponse::success(
            data: new ExperienceResource($this->profileService->getExperience($request->user(), $experience)),
            message: 'Experience retrieved successfully.',
        );
    }

    public function update(UpdateExperienceRequest $request, Experience $experience): JsonResponse
    {
        return ApiResponse::success(
            data: new ExperienceResource($this->profileService->updateExperience($request->user(), $experience, $request->validated())),
            message: 'Experience updated successfully.',
        );
    }

    public function destroy(DestroyExperienceRequest $request, Experience $experience): JsonResponse
    {
        $this->profileService->deleteExperience($request->user(), $experience);

        return ApiResponse::success(
            data: null,
            message: 'Experience deleted successfully.',
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\DestroyEducationRequest;
use App\Http\Requests\Api\V1\Profile\EducationIndexRequest;
use App\Http\Requests\Api\V1\Profile\ShowEducationRequest;
use App\Http\Requests\Api\V1\Profile\StoreEducationRequest;
use App\Http\Requests\Api\V1\Profile\UpdateEducationRequest;
use App\Http\Resources\Api\V1\EducationResource;
use App\Models\Education;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EducationController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {
    }

    public function index(EducationIndexRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: EducationResource::collection($this->profileService->getEducation($request->user())),
            message: 'Education records retrieved successfully.',
        );
    }

    public function store(StoreEducationRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new EducationResource($this->profileService->createEducation($request->user(), $request->validated())),
            message: 'Education record created successfully.',
            status: 201,
        );
    }

    public function show(ShowEducationRequest $request, Education $education): JsonResponse
    {
        return ApiResponse::success(
            data: new EducationResource($this->profileService->getEducationRecord($request->user(), $education)),
            message: 'Education record retrieved successfully.',
        );
    }

    public function update(UpdateEducationRequest $request, Education $education): JsonResponse
    {
        return ApiResponse::success(
            data: new EducationResource($this->profileService->updateEducation($request->user(), $education, $request->validated())),
            message: 'Education record updated successfully.',
        );
    }

    public function destroy(DestroyEducationRequest $request, Education $education): JsonResponse
    {
        $this->profileService->deleteEducation($request->user(), $education);

        return ApiResponse::success(
            data: null,
            message: 'Education record deleted successfully.',
        );
    }
}

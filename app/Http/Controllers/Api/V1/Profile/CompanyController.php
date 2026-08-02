<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\ShowCompanyRequest;
use App\Http\Requests\Api\V1\Profile\UpdateCompanyCoverRequest;
use App\Http\Requests\Api\V1\Profile\UpdateCompanyRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Services\OptionalImageService;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
        private readonly OptionalImageService $images,
    ) {}

    public function show(ShowCompanyRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyResource($this->profileService->getCompany($request->user())),
            message: __('companies.retrieved'),
        );
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyResource($this->profileService->updateCompany($request->user(), $request->validated())),
            message: __('companies.updated'),
        );
    }

    public function updateCover(UpdateCompanyCoverRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyResource($this->images->updateCompanyCover($request->user('sanctum'), $request->file('image'))),
            message: __('companies.cover_image_updated'),
        );
    }

    public function destroyCover(UpdateCompanyCoverRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyResource($this->images->removeCompanyCover($request->user('sanctum'))),
            message: __('companies.cover_image_removed'),
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\ShowCompanyRequest;
use App\Http\Requests\Api\V1\Profile\UpdateCompanyRequest;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {
    }

    public function show(ShowCompanyRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyResource($this->profileService->getCompany($request->user())),
            message: 'Company retrieved successfully.',
        );
    }

    public function update(UpdateCompanyRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new CompanyResource($this->profileService->updateCompany($request->user(), $request->validated())),
            message: 'Company updated successfully.',
        );
    }
}

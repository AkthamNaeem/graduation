<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DeleteAdminRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminRequest;
use App\Http\Requests\Api\V1\Admin\StoreAdminTestRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAdminTestRequest;
use App\Http\Resources\Api\V1\TestResource;
use App\Models\Test;
use App\Services\TestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminTestController extends Controller
{
    public function __construct(
        private readonly TestService $testService,
    ) {
    }

    public function index(IndexAdminRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: TestResource::collection(
                $this->testService->getCatalogTests($request->user('sanctum'), $request->integer('per_page', 15)),
            ),
            message: 'Tests retrieved successfully.',
        );
    }

    public function store(StoreAdminTestRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new TestResource($this->testService->createCatalogTest($request->user('sanctum'), $request->validated())),
            message: 'Test created successfully.',
            status: 201,
        );
    }

    public function update(UpdateAdminTestRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(
            data: new TestResource($this->testService->updateCatalogTest($test, $request->validated())),
            message: 'Test updated successfully.',
        );
    }

    public function destroy(DeleteAdminRequest $request, Test $test): JsonResponse
    {
        $this->testService->deleteCatalogTest($test);

        return ApiResponse::success(
            data: null,
            message: 'Test deleted successfully.',
        );
    }
}

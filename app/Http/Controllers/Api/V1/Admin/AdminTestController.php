<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DeleteAdminRequest;
use App\Http\Requests\Api\V1\Admin\IndexAdminRequest;
use App\Http\Requests\Api\V1\Admin\StoreAdminTestRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAdminTestRequest;
use App\Http\Resources\Api\V1\TestResource;
use App\Models\Test;
use App\Models\User;
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
        $tests = Test::query()
            ->with('questions.options')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return ApiResponse::success(
            data: TestResource::collection($tests),
            message: 'Tests retrieved successfully.',
        );
    }

    public function store(StoreAdminTestRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new TestResource($this->testService->createCatalogTest($this->authenticatedUser($request), $request->validated())),
            message: 'Test created successfully.',
            status: 201,
        );
    }

    public function update(UpdateAdminTestRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(
            data: new TestResource($this->testService->updateCatalogTest($this->authenticatedUser($request), $test, $request->validated())),
            message: 'Test updated successfully.',
        );
    }

    public function destroy(DeleteAdminRequest $request, Test $test): JsonResponse
    {
        $this->testService->deleteCatalogTest($this->authenticatedUser($request), $test);

        return ApiResponse::success(
            data: null,
            message: 'Test deleted successfully.',
        );
    }

    private function authenticatedUser($request): User
    {
        $user = $request->user('sanctum') ?? $request->user();

        return $user instanceof User
            ? $user
            : throw new \RuntimeException('Authenticated user could not be resolved.');
    }
}

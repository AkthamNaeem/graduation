<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\DeleteTestCatalogRequest;
use App\Http\Requests\Api\V1\Test\IndexTestCatalogRequest;
use App\Http\Requests\Api\V1\Test\ShowTestCatalogRequest;
use App\Http\Requests\Api\V1\Test\StoreTestCatalogRequest;
use App\Http\Requests\Api\V1\Test\UpdateTestCatalogRequest;
use App\Http\Resources\Api\V1\TestResource;
use App\Models\Test;
use App\Models\User;
use App\Services\TestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TestCatalogController extends Controller
{
    public function __construct(
        private readonly TestService $testService,
    ) {
    }

    public function index(IndexTestCatalogRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: TestResource::collection(
                $this->testService->getCatalogTests(
                    $this->authenticatedUser($request),
                    $request->integer('per_page', 15),
                ),
            ),
            message: 'Tests retrieved successfully.',
        );
    }

    public function store(StoreTestCatalogRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: new TestResource($this->testService->createCatalogTest($this->authenticatedUser($request), $request->validated())),
            message: 'Test created successfully.',
            status: 201,
        );
    }

    public function show(ShowTestCatalogRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(
            data: new TestResource($this->testService->getCatalogTest($this->authenticatedUser($request), $test)),
            message: 'Test retrieved successfully.',
        );
    }

    public function update(UpdateTestCatalogRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(
            data: new TestResource($this->testService->updateCatalogTest($this->authenticatedUser($request), $test, $request->validated())),
            message: 'Test updated successfully.',
        );
    }

    public function destroy(DeleteTestCatalogRequest $request, Test $test): JsonResponse
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

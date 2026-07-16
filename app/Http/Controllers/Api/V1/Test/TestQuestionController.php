<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\DeleteTestOptionRequest;
use App\Http\Requests\Api\V1\Test\DeleteTestQuestionRequest;
use App\Http\Requests\Api\V1\Test\IndexTestQuestionRequest;
use App\Http\Requests\Api\V1\Test\ReorderTestOptionRequest;
use App\Http\Requests\Api\V1\Test\ReorderTestQuestionRequest;
use App\Http\Requests\Api\V1\Test\ShowTestQuestionRequest;
use App\Http\Requests\Api\V1\Test\StoreTestOptionRequest;
use App\Http\Requests\Api\V1\Test\StoreTestQuestionRequest;
use App\Http\Requests\Api\V1\Test\UpdateTestOptionRequest;
use App\Http\Requests\Api\V1\Test\UpdateTestQuestionRequest;
use App\Http\Resources\Api\V1\TestOptionResource;
use App\Http\Resources\Api\V1\TestQuestionResource;
use App\Models\Test;
use App\Models\TestOption;
use App\Models\TestQuestion;
use App\Services\TestQuestionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TestQuestionController extends Controller
{
    public function __construct(private readonly TestQuestionService $service) {}

    public function index(IndexTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(TestQuestionResource::collection($this->service->listQuestions($test)), 'Test questions retrieved successfully.');
    }

    public function store(StoreTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(new TestQuestionResource($this->service->createQuestion($test, $request->validated())), 'Test question created successfully.', 201);
    }

    public function show(ShowTestQuestionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(new TestQuestionResource($this->service->getQuestion($test, $question)), 'Test question retrieved successfully.');
    }

    public function update(UpdateTestQuestionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(new TestQuestionResource($this->service->updateQuestion($test, $question, $request->validated())), 'Test question updated successfully.');
    }

    public function destroy(DeleteTestQuestionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        $this->service->deleteQuestion($test, $question);

        return ApiResponse::success(null, 'Test question deleted successfully.');
    }

    public function reorder(ReorderTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(TestQuestionResource::collection($this->service->reorderQuestions($test, $request->validated('questions'))), 'Test questions reordered successfully.');
    }

    public function storeOption(StoreTestOptionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(new TestOptionResource($this->service->createOption($test, $question, $request->validated())), 'Test option created successfully.', 201);
    }

    public function updateOption(UpdateTestOptionRequest $request, Test $test, TestQuestion $question, TestOption $option): JsonResponse
    {
        return ApiResponse::success(new TestOptionResource($this->service->updateOption($test, $question, $option, $request->validated())), 'Test option updated successfully.');
    }

    public function destroyOption(DeleteTestOptionRequest $request, Test $test, TestQuestion $question, TestOption $option): JsonResponse
    {
        $this->service->deleteOption($test, $question, $option);

        return ApiResponse::success(null, 'Test option deleted successfully.');
    }

    public function reorderOptions(ReorderTestOptionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(TestOptionResource::collection($this->service->reorderOptions($test, $question, $request->validated('options'))), 'Test options reordered successfully.');
    }
}

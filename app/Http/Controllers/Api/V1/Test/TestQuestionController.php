<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\ManageTestQuestionRequest;
use App\Http\Requests\Api\V1\Test\ReorderTestQuestionsRequest;
use App\Http\Requests\Api\V1\Test\StoreTestQuestionRequest;
use App\Http\Requests\Api\V1\Test\UpdateTestQuestionRequest;
use App\Http\Resources\Api\V1\TestQuestionResource;
use App\Models\Test;
use App\Models\TestQuestion;
use App\Models\User;
use App\Services\TestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TestQuestionController extends Controller
{
    public function __construct(
        private readonly TestService $testService,
    ) {
    }

    public function index(ManageTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(
            data: TestQuestionResource::collection($this->testService->listQuestions($this->authenticatedUser($request), $test)),
            message: 'Test questions retrieved successfully.',
        );
    }

    public function store(StoreTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(
            data: new TestQuestionResource($this->testService->createQuestion($this->authenticatedUser($request), $test, $request->validated())),
            message: 'Test question created successfully.',
            status: 201,
        );
    }

    public function show(ManageTestQuestionRequest $request, Test $test, TestQuestion $testQuestion): JsonResponse
    {
        $question = $this->testService
            ->listQuestions($this->authenticatedUser($request), $test)
            ->firstWhere('id', $testQuestion->id);

        abort_if($question === null, 404);

        return ApiResponse::success(
            data: new TestQuestionResource($question->load('options')),
            message: 'Test question retrieved successfully.',
        );
    }

    public function update(UpdateTestQuestionRequest $request, Test $test, TestQuestion $testQuestion): JsonResponse
    {
        return ApiResponse::success(
            data: new TestQuestionResource($this->testService->updateQuestion($this->authenticatedUser($request), $test, $testQuestion, $request->validated())),
            message: 'Test question updated successfully.',
        );
    }

    public function destroy(ManageTestQuestionRequest $request, Test $test, TestQuestion $testQuestion): JsonResponse
    {
        $this->testService->deleteQuestion($this->authenticatedUser($request), $test, $testQuestion);

        return ApiResponse::success(
            data: null,
            message: 'Test question deleted successfully.',
        );
    }

    public function reorder(ReorderTestQuestionsRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(
            data: TestQuestionResource::collection($this->testService->reorderQuestions($this->authenticatedUser($request), $test, $request->validated('questions'))),
            message: 'Test questions reordered successfully.',
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

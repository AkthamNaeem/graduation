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
use App\Http\Requests\Api\V1\Test\UpdateTestQuestionImageRequest;
use App\Http\Requests\Api\V1\Test\UpdateTestQuestionRequest;
use App\Http\Resources\Api\V1\TestOptionResource;
use App\Http\Resources\Api\V1\TestQuestionResource;
use App\Models\Test;
use App\Models\TestOption;
use App\Models\TestQuestion;
use App\Services\OptionalImageService;
use App\Services\TestQuestionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestQuestionController extends Controller
{
    public function __construct(
        private readonly TestQuestionService $service,
        private readonly OptionalImageService $images,
    ) {}

    public function index(IndexTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(TestQuestionResource::collection($this->service->listQuestions($test)), __('tests.questions'));
    }

    public function store(StoreTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(new TestQuestionResource($this->service->createQuestion($test, $request->validated(), $request->user('sanctum'))), __('tests.question_created'), 201);
    }

    public function show(ShowTestQuestionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(new TestQuestionResource($this->service->getQuestion($test, $question)), __('tests.question'));
    }

    public function update(UpdateTestQuestionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(new TestQuestionResource($this->service->updateQuestion($test, $question, $request->validated(), $request->user('sanctum'))), __('tests.question_updated'));
    }

    public function destroy(DeleteTestQuestionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        $this->service->deleteQuestion($test, $question, $request->user('sanctum'));

        return ApiResponse::success(null, __('tests.question_deleted'));
    }

    public function updateImage(UpdateTestQuestionImageRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(
            new TestQuestionResource($this->images->updateQuestionImage($request->user('sanctum'), $test, $question, $request->file('image'))),
            __('tests.question_image_updated'),
        );
    }

    public function showImage(UpdateTestQuestionImageRequest $request, Test $test, TestQuestion $question): StreamedResponse
    {
        return $this->images->questionImageResponse($question);
    }

    public function destroyImage(UpdateTestQuestionImageRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(
            new TestQuestionResource($this->images->removeQuestionImage($request->user('sanctum'), $test, $question)),
            __('tests.question_image_removed'),
        );
    }

    public function reorder(ReorderTestQuestionRequest $request, Test $test): JsonResponse
    {
        return ApiResponse::success(TestQuestionResource::collection($this->service->reorderQuestions($test, $request->validated('questions'))), __('tests.questions_reordered'));
    }

    public function storeOption(StoreTestOptionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(new TestOptionResource($this->service->createOption($test, $question, $request->validated())), __('tests.option_created'), 201);
    }

    public function updateOption(UpdateTestOptionRequest $request, Test $test, TestQuestion $question, TestOption $option): JsonResponse
    {
        return ApiResponse::success(new TestOptionResource($this->service->updateOption($test, $question, $option, $request->validated())), __('tests.option_updated'));
    }

    public function destroyOption(DeleteTestOptionRequest $request, Test $test, TestQuestion $question, TestOption $option): JsonResponse
    {
        $this->service->deleteOption($test, $question, $option);

        return ApiResponse::success(null, __('tests.option_deleted'));
    }

    public function reorderOptions(ReorderTestOptionRequest $request, Test $test, TestQuestion $question): JsonResponse
    {
        return ApiResponse::success(TestOptionResource::collection($this->service->reorderOptions($test, $question, $request->validated('options'))), __('tests.options_reordered'));
    }
}

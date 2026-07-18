<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\BulkUpsertTestAnswerRequest;
use App\Http\Requests\Api\V1\Test\DeleteTestAnswerRequest;
use App\Http\Requests\Api\V1\Test\DownloadTestAnswerFileRequest;
use App\Http\Requests\Api\V1\Test\IndexTestAnswerRequest;
use App\Http\Requests\Api\V1\Test\UpsertTestAnswerRequest;
use App\Http\Resources\Api\V1\TestAnswerResource;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use App\Services\PrivateFileStorageService;
use App\Services\TestAnswerService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestAnswerController extends Controller
{
    public function __construct(
        private readonly TestAnswerService $service,
        private readonly PrivateFileStorageService $privateStorage,
    ) {}

    public function index(IndexTestAnswerRequest $request, TestAttempt $testAttempt): JsonResponse
    {
        return ApiResponse::success(TestAnswerResource::collection($this->service->listAnswers($testAttempt)), 'Test answers retrieved successfully.');
    }

    public function upsert(UpsertTestAnswerRequest $request, TestAttempt $testAttempt, TestQuestion $question): JsonResponse
    {
        $answer = $this->service->upsertAnswer($testAttempt, $question, $request->validated(), $request->file('answer_file'));

        return ApiResponse::success(new TestAnswerResource($answer), 'Test answer saved successfully.');
    }

    public function bulk(BulkUpsertTestAnswerRequest $request, TestAttempt $testAttempt): JsonResponse
    {
        return ApiResponse::success(
            TestAnswerResource::collection($this->service->bulkUpsert($testAttempt, $request->validated('answers'))),
            'Test answers saved successfully.',
        );
    }

    public function destroy(DeleteTestAnswerRequest $request, TestAttempt $testAttempt, TestQuestion $question): JsonResponse
    {
        $this->service->deleteAnswer($testAttempt, $question);

        return ApiResponse::success(null, 'Test answer deleted successfully.');
    }

    public function download(DownloadTestAnswerFileRequest $request, TestAttempt $testAttempt, TestQuestion $question): StreamedResponse
    {
        $answer = $this->service->fileAnswer($testAttempt, $question);

        return $this->privateStorage->downloadResponse($answer->file_disk, $answer->file_path, $answer->file_original_name, $answer->file_mime_type);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\IndexTestAttemptQuestionRequest;
use App\Http\Resources\Api\V1\CandidateTestQuestionResource;
use App\Models\TestAttempt;
use App\Services\TestAttemptContentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TestAttemptQuestionController extends Controller
{
    public function __construct(private readonly TestAttemptContentService $service) {}

    public function index(IndexTestAttemptQuestionRequest $request, TestAttempt $testAttempt): JsonResponse
    {
        return ApiResponse::success(
            CandidateTestQuestionResource::collection($this->service->questions($testAttempt)),
            'Test attempt questions retrieved successfully.',
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\BulkManualTestAnswerGradingRequest;
use App\Http\Requests\Api\V1\Test\DeleteManualTestAnswerGradingRequest;
use App\Http\Requests\Api\V1\Test\UpsertManualTestAnswerGradingRequest;
use App\Http\Resources\Api\V1\TestAttemptResultResource;
use App\Models\TestAttempt;
use App\Models\TestQuestion;
use App\Models\User;
use App\Services\TestManualGradingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TestManualGradingController extends Controller
{
    public function __construct(private readonly TestManualGradingService $service) {}

    public function upsert(
        UpsertManualTestAnswerGradingRequest $request,
        TestAttempt $testAttempt,
        TestQuestion $question,
    ): JsonResponse {
        return ApiResponse::success(
            new TestAttemptResultResource($this->service->upsert(
                $this->authenticatedUser($request),
                $testAttempt,
                $question,
                $request->validated(),
            )),
            'Manual answer grading saved successfully.',
        );
    }

    public function bulk(BulkManualTestAnswerGradingRequest $request, TestAttempt $testAttempt): JsonResponse
    {
        return ApiResponse::success(
            new TestAttemptResultResource($this->service->bulkUpsert(
                $this->authenticatedUser($request),
                $testAttempt,
                $request->validated('gradings'),
            )),
            'Manual answer gradings saved successfully.',
        );
    }

    public function destroy(
        DeleteManualTestAnswerGradingRequest $request,
        TestAttempt $testAttempt,
        TestQuestion $question,
    ): JsonResponse {
        return ApiResponse::success(
            new TestAttemptResultResource($this->service->delete(
                $this->authenticatedUser($request),
                $testAttempt,
                $question,
            )),
            'Manual answer grading removed successfully.',
        );
    }

    private function authenticatedUser(Request $request): User
    {
        $token = $request->bearerToken();
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User
            ? $tokenable->withAccessToken($accessToken)
            : throw new \RuntimeException('Authenticated user could not be resolved.');
    }
}

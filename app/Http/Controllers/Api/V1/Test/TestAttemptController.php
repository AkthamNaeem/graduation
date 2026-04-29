<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\EvaluateTestAttemptRequest;
use App\Http\Requests\Api\V1\Test\StartTestAttemptRequest;
use App\Http\Requests\Api\V1\Test\SubmitTestAttemptRequest;
use App\Http\Resources\Api\V1\TestAttemptResource;
use App\Models\ApplicationTestAssignment;
use App\Models\TestAttempt;
use App\Models\User;
use App\Services\TestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TestAttemptController extends Controller
{
    public function __construct(
        private readonly TestService $testService,
    ) {
    }

    public function start(StartTestAttemptRequest $request, ApplicationTestAssignment $applicationTestAssignment): JsonResponse
    {
        return ApiResponse::success(
            data: new TestAttemptResource(
                $this->testService->startAttempt($this->authenticatedUser($request), $applicationTestAssignment),
            ),
            message: 'Test attempt started successfully.',
            status: 201,
        );
    }

    public function submit(SubmitTestAttemptRequest $request, ApplicationTestAssignment $applicationTestAssignment): JsonResponse
    {
        return ApiResponse::success(
            data: new TestAttemptResource(
                $this->testService->submitAttempt(
                    $this->authenticatedUser($request),
                    $applicationTestAssignment,
                    $request->validated('answers'),
                ),
            ),
            message: 'Test attempt submitted successfully.',
        );
    }

    public function evaluate(EvaluateTestAttemptRequest $request, TestAttempt $testAttempt): JsonResponse
    {
        return ApiResponse::success(
            data: new TestAttemptResource(
                $this->testService->evaluateAttempt(
                    $this->authenticatedUser($request),
                    $testAttempt,
                    $request->validated('score'),
                    $request->validated('feedback'),
                ),
            ),
            message: 'Test attempt evaluated successfully.',
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

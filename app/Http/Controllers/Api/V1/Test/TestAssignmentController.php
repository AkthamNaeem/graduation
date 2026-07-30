<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\AssignTestRequest;
use App\Http\Requests\Api\V1\Test\ListApplicationTestsRequest;
use App\Http\Requests\Api\V1\Test\ListMyTestsRequest;
use App\Http\Resources\Api\V1\ApplicationTestAssignmentResource;
use App\Http\Resources\Api\V1\CandidateApplicationTestAssignmentResource;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\TestService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TestAssignmentController extends Controller
{
    public function __construct(
        private readonly TestService $testService,
    ) {}

    public function assign(AssignTestRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new ApplicationTestAssignmentResource(
                $this->testService->assignTest(
                    $this->authenticatedUser($request),
                    $jobApplication,
                    (int) $request->validated('test_id'),
                    $request->validated('note'),
                    $request->validated('deadline_at'),
                    (int) $request->validated('max_attempts', 1),
                ),
            ),
            message: __('tests.assigned'),
            status: 201,
        );
    }

    public function indexByApplication(ListApplicationTestsRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: ApplicationTestAssignmentResource::collection(
                $this->testService->getApplicationAssignments($jobApplication),
            ),
            message: __('tests.application_list'),
        );
    }

    public function my(ListMyTestsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: CandidateApplicationTestAssignmentResource::collection(
                $this->testService->getMyAssignments(
                    $this->authenticatedUser($request),
                    $request->integer('per_page', 15),
                ),
            ),
            message: __('tests.assigned_list'),
        );
    }

    private function authenticatedUser(Request $request): User
    {
        $token = $request->bearerToken();
        $accessToken = $token ? PersonalAccessToken::findToken($token) : null;
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User
            ? $tokenable->withAccessToken($accessToken)
            : throw new \RuntimeException(__('auth.user_unresolved'));
    }
}

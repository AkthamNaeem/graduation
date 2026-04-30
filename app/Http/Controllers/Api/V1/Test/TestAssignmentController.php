<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\AssignTestRequest;
use App\Http\Requests\Api\V1\Test\ListApplicationTestsRequest;
use App\Http\Requests\Api\V1\Test\ListMyTestsRequest;
use App\Http\Resources\Api\V1\ApplicationTestAssignmentResource;
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
    ) {
    }

    public function assign(AssignTestRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new ApplicationTestAssignmentResource(
                $this->testService->assignTest(
                    $this->authenticatedUser($request),
                    $jobApplication,
                    (int) $request->validated('test_id'),
                    $request->validated('note'),
                ),
            ),
            message: 'Test assigned successfully.',
            status: 201,
        );
    }

    public function indexByApplication(ListApplicationTestsRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: ApplicationTestAssignmentResource::collection(
                $this->testService->getApplicationAssignments($jobApplication),
            ),
            message: 'Application tests retrieved successfully.',
        );
    }

    public function my(ListMyTestsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: ApplicationTestAssignmentResource::collection(
                $this->testService->getMyAssignments(
                    $this->authenticatedUser($request),
                    $request->integer('per_page', 15),
                ),
            ),
            message: 'Assigned tests retrieved successfully.',
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

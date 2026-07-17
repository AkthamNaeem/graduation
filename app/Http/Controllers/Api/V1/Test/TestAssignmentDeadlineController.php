<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\ExtendTestAssignmentDeadlineRequest;
use App\Http\Requests\Api\V1\Test\IndexTestAssignmentDeadlineHistoryRequest;
use App\Http\Resources\Api\V1\ApplicationTestAssignmentResource;
use App\Http\Resources\Api\V1\TestAssignmentDeadlineChangeResource;
use App\Models\ApplicationTestAssignment;
use App\Models\User;
use App\Services\TestAssignmentDeadlineService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TestAssignmentDeadlineController extends Controller
{
    public function __construct(
        private readonly TestAssignmentDeadlineService $deadlineService,
    ) {}

    public function update(ExtendTestAssignmentDeadlineRequest $request, ApplicationTestAssignment $applicationTestAssignment): JsonResponse
    {
        return ApiResponse::success(
            new ApplicationTestAssignmentResource($this->deadlineService->extend(
                $this->authenticatedUser($request),
                $applicationTestAssignment,
                $request->validated(),
            )),
            'Test assignment deadline updated successfully.',
        );
    }

    public function history(IndexTestAssignmentDeadlineHistoryRequest $request, ApplicationTestAssignment $applicationTestAssignment): JsonResponse
    {
        return ApiResponse::success(
            TestAssignmentDeadlineChangeResource::collection($this->deadlineService->history($applicationTestAssignment)),
            'Test assignment deadline history retrieved successfully.',
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

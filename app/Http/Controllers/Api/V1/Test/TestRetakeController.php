<?php

namespace App\Http\Controllers\Api\V1\Test;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Test\GrantTestRetakeRequest;
use App\Http\Requests\Api\V1\Test\ShowTestAssignmentSeriesRequest;
use App\Http\Requests\Api\V1\Test\UpdateTestRetakePolicyRequest;
use App\Http\Resources\Api\V1\ApplicationTestAssignmentResource;
use App\Http\Resources\Api\V1\TestAssignmentSeriesResource;
use App\Models\ApplicationTestAssignment;
use App\Models\User;
use App\Services\TestRetakeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TestRetakeController extends Controller
{
    public function __construct(
        private readonly TestRetakeService $retakeService,
    ) {}

    public function updatePolicy(UpdateTestRetakePolicyRequest $request, ApplicationTestAssignment $applicationTestAssignment): JsonResponse
    {
        return ApiResponse::success(
            new ApplicationTestAssignmentResource($this->retakeService->updatePolicy(
                $this->authenticatedUser($request),
                $applicationTestAssignment,
                $request->validated(),
            )),
            __('tests.retake_policy'),
        );
    }

    public function grant(GrantTestRetakeRequest $request, ApplicationTestAssignment $applicationTestAssignment): JsonResponse
    {
        return ApiResponse::success(
            new ApplicationTestAssignmentResource($this->retakeService->grant(
                $this->authenticatedUser($request),
                $applicationTestAssignment,
                $request->validated(),
            )),
            __('tests.retake_granted'),
            201,
        );
    }

    public function series(ShowTestAssignmentSeriesRequest $request, ApplicationTestAssignment $applicationTestAssignment): JsonResponse
    {
        return ApiResponse::success(
            new TestAssignmentSeriesResource($this->retakeService->getSeries($applicationTestAssignment)),
            __('tests.series'),
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

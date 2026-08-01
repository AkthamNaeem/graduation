<?php

namespace App\Http\Controllers\Api\V1\Activity;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Activity\ActivityIndexRequest;
use App\Http\Resources\Api\V1\Activity\ActivityItemResource;
use App\Http\Resources\Api\V1\Activity\ActivityScheduleItemResource;
use App\Services\Activity\ActivityService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ActivityController extends Controller
{
    public function __construct(private readonly ActivityService $activityService) {}

    public function index(ActivityIndexRequest $request): JsonResponse
    {
        $result = $this->activityService->index(
            $request->user('sanctum'),
            $request->validated(),
        );

        return ApiResponse::success(
            data: [
                'summary' => $result['summary'],
                'upcoming_schedule' => ActivityScheduleItemResource::collection($result['upcoming_schedule'])->resolve($request),
                'requires_action' => ActivityItemResource::collection($result['requires_action'])->resolve($request),
                'feed' => [
                    'data' => ActivityItemResource::collection($result['feed']->items())->resolve($request),
                    'meta' => [
                        'current_page' => $result['feed']->currentPage(),
                        'last_page' => $result['feed']->lastPage(),
                        'per_page' => $result['feed']->perPage(),
                        'total' => $result['feed']->total(),
                    ],
                ],
            ],
            message: __('activity.retrieved'),
        );
    }
}

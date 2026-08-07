<?php

namespace App\Http\Controllers\Api\V1\Interview;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Interview\CreateInterviewVideoSessionRequest;
use App\Models\Interview;
use App\Models\User;
use App\Services\InterviewVideoService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class InterviewVideoController extends Controller
{
    public function __construct(
        private readonly InterviewVideoService $interviewVideoService,
    ) {}

    public function store(CreateInterviewVideoSessionRequest $request, Interview $interview): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return ApiResponse::success(
            $this->interviewVideoService->issueSession($actor, $interview),
            __('interviews.video_session_issued'),
        );
    }
}

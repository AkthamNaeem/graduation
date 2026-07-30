<?php

namespace App\Http\Controllers\Api\V1\Interview;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Interview\CancelInterviewRequest;
use App\Http\Requests\Api\V1\Interview\CompleteInterviewRequest;
use App\Http\Requests\Api\V1\Interview\ConfirmInterviewRequest;
use App\Http\Requests\Api\V1\Interview\CreateInterviewRequest;
use App\Http\Requests\Api\V1\Interview\DeleteInterviewRequest;
use App\Http\Requests\Api\V1\Interview\EvaluateInterviewRequest;
use App\Http\Requests\Api\V1\Interview\InterviewScheduleHistoryRequest;
use App\Http\Requests\Api\V1\Interview\InterviewStatusHistoryRequest;
use App\Http\Requests\Api\V1\Interview\ListApplicationInterviewsRequest;
use App\Http\Requests\Api\V1\Interview\ListMyInterviewsRequest;
use App\Http\Requests\Api\V1\Interview\MarkInterviewNoShowRequest;
use App\Http\Requests\Api\V1\Interview\RescheduleInterviewRequest;
use App\Http\Requests\Api\V1\Interview\ShowInterviewRequest;
use App\Http\Requests\Api\V1\Interview\UpdateInterviewAttendanceRequest;
use App\Http\Requests\Api\V1\Interview\UpdateInterviewRequest;
use App\Http\Resources\Api\V1\InterviewResource;
use App\Http\Resources\Api\V1\InterviewScheduleChangeResource;
use App\Http\Resources\Api\V1\InterviewStatusHistoryResource;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\InterviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class InterviewController extends Controller
{
    public function __construct(
        private readonly InterviewService $interviewService,
    ) {}

    public function store(CreateInterviewRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: new InterviewResource(
                $this->interviewService->createInterview(
                    $this->authenticatedUser($request),
                    $jobApplication,
                    $request->validated(),
                ),
            ),
            message: __('interviews.scheduled'),
            status: 201,
        );
    }

    public function indexByApplication(ListApplicationInterviewsRequest $request, JobApplication $jobApplication): JsonResponse
    {
        return ApiResponse::success(
            data: InterviewResource::collection(
                $this->interviewService->getApplicationInterviews($jobApplication),
            ),
            message: __('interviews.application_list'),
        );
    }

    public function update(UpdateInterviewRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            data: new InterviewResource(
                $this->interviewService->updateInterview(
                    $this->authenticatedUser($request),
                    $interview,
                    $request->validated(),
                ),
            ),
            message: __('interviews.updated'),
        );
    }

    public function destroy(DeleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        $this->interviewService->deleteInterview($this->authenticatedUser($request), $interview);

        return ApiResponse::success(
            data: null,
            message: __('interviews.cancelled'),
        );
    }

    public function confirm(ConfirmInterviewRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            new InterviewResource($this->interviewService->confirmInterview($this->authenticatedUser($request), $interview)),
            __('interviews.confirmed'),
        );
    }

    public function reschedule(RescheduleInterviewRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            new InterviewResource($this->interviewService->rescheduleInterview($this->authenticatedUser($request), $interview, $request->validated())),
            __('interviews.rescheduled'),
        );
    }

    public function cancel(CancelInterviewRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            new InterviewResource($this->interviewService->cancelInterview(
                $this->authenticatedUser($request), $interview, $request->string('reason')->toString(), $request->validated('candidate_message'),
            )),
            __('interviews.cancelled'),
        );
    }

    public function attendance(UpdateInterviewAttendanceRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            new InterviewResource($this->interviewService->updateAttendance($this->authenticatedUser($request), $interview, $request->validated())),
            __('interviews.attendance_updated'),
        );
    }

    public function noShow(MarkInterviewNoShowRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            new InterviewResource($this->interviewService->markNoShow(
                $this->authenticatedUser($request), $interview, $request->string('party')->toString(), $request->string('reason')->toString(),
            )),
            __('interviews.no_show'),
        );
    }

    public function statusHistory(InterviewStatusHistoryRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(InterviewStatusHistoryResource::collection($this->interviewService->statusHistory($interview, $request->integer('per_page', 25))), __('interviews.status_history'));
    }

    public function scheduleHistory(InterviewScheduleHistoryRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(InterviewScheduleChangeResource::collection($this->interviewService->scheduleHistory($interview, $request->integer('per_page', 25))), __('interviews.schedule_history'));
    }

    public function complete(CompleteInterviewRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            data: new InterviewResource(
                $this->interviewService->completeInterview(
                    $this->authenticatedUser($request),
                    $interview,
                    $request->validated('completion_note'),
                ),
            ),
            message: __('interviews.completed'),
        );
    }

    public function evaluate(EvaluateInterviewRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            data: new InterviewResource(
                $this->interviewService->evaluateInterview(
                    $this->authenticatedUser($request),
                    $interview,
                    $request->validated(),
                ),
            ),
            message: __('interviews.evaluated'),
        );
    }

    public function my(ListMyInterviewsRequest $request): JsonResponse
    {
        return ApiResponse::success(
            data: InterviewResource::collection(
                $this->interviewService->getMyInterviews(
                    $this->authenticatedUser($request),
                    $request->integer('per_page', 15),
                ),
            ),
            message: __('interviews.my_list'),
        );
    }

    public function show(ShowInterviewRequest $request, Interview $interview): JsonResponse
    {
        return ApiResponse::success(
            data: new InterviewResource(
                $this->interviewService->getInterview($interview, $this->authenticatedUser($request)),
            ),
            message: __('interviews.retrieved'),
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

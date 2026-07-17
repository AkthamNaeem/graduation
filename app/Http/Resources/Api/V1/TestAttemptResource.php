<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use App\Models\TestAttempt;
use App\Services\TestAttemptTimingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin TestAttempt */
class TestAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $request->bearerToken();
        $role = $token ? PersonalAccessToken::findToken($token)?->tokenable?->role : null;
        $assignment = $this->applicationTestAssignment;
        $timing = app(TestAttemptTimingService::class);
        $deadline = $timing->effectiveDeadline($this->resource);
        $timeExpired = $timing->isExpired($this->resource);
        $expired = $this->submitted_at === null && $timeExpired;

        return [
            'id' => $this->id,
            'application_test_assignment_id' => $this->application_test_assignment_id,
            'attempt_number' => $assignment?->attempt_number,
            'deadline_at' => $assignment?->deadline_at?->toISOString(),
            'duration_deadline_at' => $timing->durationDeadline($this->resource)->toISOString(),
            'effective_deadline_at' => $deadline->toISOString(),
            'remaining_seconds' => $timing->remainingSeconds($this->resource),
            'is_time_expired' => $timeExpired,
            'is_expired' => $expired,
            'can_edit_answers' => $this->submitted_at === null && ! $expired,
            'can_submit' => $this->submitted_at === null && ! $expired,
            'answers' => TestAnswerResource::collection($this->whenLoaded('testAnswers')),
            'grading_status' => $this->grading_status?->value,
            'objective_score' => $this->objective_score,
            'objective_max_score' => $this->objective_max_score,
            'manual_score' => $this->manual_score,
            'manual_max_score' => $this->manual_max_score,
            'total_score' => $this->total_score,
            'max_score' => $this->max_score,
            'percentage' => $this->percentage,
            'is_passing_score_met' => $this->passingScoreMet(),
            'auto_graded_at' => $this->auto_graded_at?->toISOString(),
            'manually_graded_at' => $this->manually_graded_at?->toISOString(),
            'score' => $this->score,
            'feedback' => $this->feedback,
            'evaluated_by_user_id' => $this->when($role !== UserRole::JOB_SEEKER, $this->evaluated_by_user_id),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

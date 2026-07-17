<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApplicationTestAssignment;
use App\Models\TestAttempt;
use App\Services\TestAttemptTimingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApplicationTestAssignment */
class CandidateApplicationTestAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $attempt = $this->relationLoaded('testAttempt') ? $this->testAttempt : null;
        if ($attempt instanceof TestAttempt) {
            $attempt->setRelation('applicationTestAssignment', $this->resource);
        }
        $timing = app(TestAttemptTimingService::class);
        $effectiveDeadline = $attempt instanceof TestAttempt ? $timing->effectiveDeadline($attempt) : null;
        $timeExpired = $attempt instanceof TestAttempt && $timing->isExpired($attempt);
        $latest = (bool) $this->getAttribute('candidate_is_latest');
        $seriesCount = (int) $this->getAttribute('candidate_series_count');
        $expired = $this->isExpired();

        return [
            'id' => $this->id,
            'assignment_id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'test_id' => $this->test_id,
            'attempt_number' => $this->attempt_number,
            'max_attempts' => $this->max_attempts,
            'attempts_remaining' => max(0, $this->max_attempts - $seriesCount),
            'is_latest_assignment' => $latest,
            'is_superseded' => ! $latest,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'deadline_at' => $this->deadline_at?->toISOString(),
            'effective_deadline_at' => $effectiveDeadline?->toISOString(),
            'is_time_expired' => $timeExpired,
            'has_deadline' => $this->hasDeadline(),
            'is_expired' => $expired,
            'remaining_seconds' => $this->remainingSeconds(),
            'can_start' => $latest && ! $attempt instanceof TestAttempt && ! $expired,
            'state' => $this->state($attempt),
            'test' => CandidateAssignedTestSummaryResource::make($this->whenLoaded('test')),
            'attempt' => $attempt instanceof TestAttempt ? [
                'id' => $attempt->id,
                'attempt_id' => $attempt->id,
                'started_at' => $attempt->started_at?->toISOString(),
                'effective_deadline_at' => $effectiveDeadline?->toISOString(),
                'remaining_seconds' => $timing->remainingSeconds($attempt),
                'is_time_expired' => $timeExpired,
                'can_edit_answers' => $attempt->submitted_at === null && ! $timeExpired,
                'can_submit' => $attempt->submitted_at === null && ! $timeExpired,
                'submitted_at' => $attempt->submitted_at?->toISOString(),
                'grading_status' => $attempt->grading_status?->value,
                'questions_url' => "/api/v1/test-attempts/{$attempt->id}/questions",
            ] : null,
        ];
    }

    private function state(?TestAttempt $attempt): string
    {
        if (! $attempt instanceof TestAttempt) {
            return 'not_started';
        }

        if ($attempt->submitted_at !== null) {
            return 'submitted';
        }

        return 'in_progress';
    }
}

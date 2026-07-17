<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\UserRole;
use App\Models\ApplicationTestAssignment;
use App\Models\TestAttempt;
use App\Services\TestAttemptTimingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin ApplicationTestAssignment */
class ApplicationTestAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attempt = $this->relationLoaded('testAttempt') ? $this->getRelation('testAttempt') : null;
        if ($attempt instanceof TestAttempt) {
            $attempt->setRelation('applicationTestAssignment', $this->resource);
        }
        $timing = app(TestAttemptTimingService::class);
        $effectiveDeadline = $attempt instanceof TestAttempt ? $timing->effectiveDeadline($attempt) : null;
        $timeExpired = $attempt instanceof TestAttempt && $timing->isExpired($attempt);
        $expired = $this->isExpired();
        $role = ($token = $request->bearerToken())
            ? PersonalAccessToken::findToken($token)?->tokenable?->role
            : null;
        $manager = $role === UserRole::EMPLOYER || $role === UserRole::ADMIN;
        $rootId = $this->seriesRootId();
        $seriesCount = ApplicationTestAssignment::query()
            ->where(fn ($query) => $query->whereKey($rootId)->orWhere('series_root_assignment_id', $rootId))
            ->count();
        $latest = $this->isLatestAssignment();

        return [
            'id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'test_id' => $this->test_id,
            'assigned_by_user_id' => $this->when($manager, $this->assigned_by_user_id),
            'attempt_number' => $this->attempt_number,
            'max_attempts' => $this->max_attempts,
            'attempts_remaining' => max(0, $this->max_attempts - $seriesCount),
            'series_root_assignment_id' => $this->series_root_assignment_id,
            'previous_assignment_id' => $this->previous_assignment_id,
            'is_latest_assignment' => $latest,
            'is_superseded' => ! $latest,
            'retake_reason' => $this->when($manager, $this->retake_reason),
            'retake_granted_by_user_id' => $this->when($manager, $this->retake_granted_by_user_id),
            'note' => $this->note,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'deadline_at' => $this->deadline_at?->toISOString(),
            'effective_deadline_at' => $effectiveDeadline?->toISOString(),
            'is_time_expired' => $timeExpired,
            'has_deadline' => $this->hasDeadline(),
            'is_expired' => $expired,
            'remaining_seconds' => $this->remainingSeconds(),
            'can_start' => $latest && ! $attempt instanceof TestAttempt && ! $expired,
            'can_edit_answers' => $attempt instanceof TestAttempt && $attempt->submitted_at === null && ! $expired,
            'can_submit' => $attempt instanceof TestAttempt && $attempt->submitted_at === null && ! $expired,
            'extension_count' => $this->when($manager, fn (): int => $this->deadlineChanges->count()),
            'latest_extension_at' => $this->when($manager, fn (): ?string => $this->deadlineChanges->max('created_at')?->toISOString()),
            'state' => $this->state(),
            'test' => TestResource::make($this->whenLoaded('test')),
            'attempt' => TestAttemptResource::make($this->whenLoaded('testAttempt')),
            'job_application' => JobApplicationResource::make($this->whenLoaded('jobApplication')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function state(): string
    {
        $attempt = $this->whenLoaded('testAttempt');

        if (! $attempt instanceof TestAttempt) {
            return 'not_started';
        }

        if ($attempt->evaluated_at !== null) {
            return 'evaluated';
        }

        if ($attempt->submitted_at !== null) {
            return 'submitted';
        }

        return 'in_progress';
    }
}

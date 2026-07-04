<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TestAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApplicationTestAssignment */
class ApplicationTestAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'test_id' => $this->test_id,
            'assigned_by_user_id' => $this->assigned_by_user_id,
            'note' => $this->note,
            'status' => $this->status,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'deadline_at' => $this->deadline_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'evaluated_at' => $this->evaluated_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'state' => $this->state(),
            'test_snapshot' => $this->safeSnapshot(),
            'test' => TestResource::make($this->whenLoaded('test')),
            'attempt' => TestAttemptResource::make($this->whenLoaded('testAttempt')),
            'job_application' => JobApplicationResource::make($this->whenLoaded('jobApplication')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function state(): string
    {
        if (is_string($this->status) && $this->status !== '') {
            return $this->status;
        }

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

    /**
     * @return array<string, mixed>|null
     */
    private function safeSnapshot(): ?array
    {
        if (! is_array($this->test_snapshot)) {
            return null;
        }

        $snapshot = $this->test_snapshot;
        $snapshot['questions'] = collect($snapshot['questions'] ?? [])
            ->map(function (array $question): array {
                $question['options'] = collect($question['options'] ?? [])
                    ->map(fn (array $option): array => collect($option)->except('is_correct')->all())
                    ->values()
                    ->all();

                return collect($question)->except('expected_answer')->all();
            })
            ->values()
            ->all();

        return $snapshot;
    }
}

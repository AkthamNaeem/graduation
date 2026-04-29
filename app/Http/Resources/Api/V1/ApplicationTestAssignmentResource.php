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
            'assigned_at' => $this->assigned_at?->toISOString(),
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

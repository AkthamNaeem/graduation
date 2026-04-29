<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Interview */
class InterviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'scheduled_by_user_id' => $this->scheduled_by_user_id,
            'completed_by_user_id' => $this->completed_by_user_id,
            'interview_type' => $this->interview_type,
            'interview_mode' => $this->interview_mode,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->location,
            'meeting_link' => $this->meeting_link,
            'note' => $this->note,
            'completion_note' => $this->completion_note,
            'completed_at' => $this->completed_at?->toISOString(),
            'state' => $this->completed_at === null ? 'scheduled' : 'completed',
            'evaluation' => InterviewEvaluationResource::make($this->whenLoaded('evaluation')),
            'job_application' => JobApplicationResource::make($this->whenLoaded('jobApplication')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

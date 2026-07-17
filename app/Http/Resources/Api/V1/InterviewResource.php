<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Interview */
class InterviewResource extends JsonResource
{
    use ResolvesResourceViewer;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $manager = $this->viewerIsManager($request);

        return [
            'id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'interview_type' => $this->interview_type,
            'interview_mode' => $this->interview_mode,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'ends_at' => $this->scheduled_at !== null && $this->duration_minutes !== null
                ? $this->scheduled_at->copy()->addMinutes((int) $this->duration_minutes)->toISOString()
                : null,
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->when($manager || $this->interview_mode === 'in_person', $this->location),
            'meeting_link' => $this->when($manager || $this->interview_mode === 'video', $this->meeting_link),
            'scheduled_by_user_id' => $this->when($manager, $this->scheduled_by_user_id),
            'completed_by_user_id' => $this->when($manager, $this->completed_by_user_id),
            'note' => $this->when($manager, $this->note),
            'completion_note' => $this->when($manager, $this->completion_note),
            'completed_at' => $this->completed_at?->toISOString(),
            'state' => $this->completed_at === null ? 'scheduled' : 'completed',
            'evaluation' => $this->when(
                $manager && $this->relationLoaded('evaluation'),
                fn () => new InterviewEvaluationResource($this->evaluation),
            ),
            'job_application' => JobApplicationResource::make($this->whenLoaded('jobApplication')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

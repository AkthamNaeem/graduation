<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use App\Models\Interview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Interview */
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
            'type' => $this->interview_type,
            'interview_type' => $this->interview_type,
            'mode' => $this->interview_mode,
            'interview_mode' => $this->interview_mode,
            'status' => $this->status,
            'scheduled_start_at' => $this->scheduled_at?->toISOString(),
            'scheduled_end_at' => $this->scheduled_end_at?->toISOString(),
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'ends_at' => $this->scheduled_end_at?->toISOString(),
            'duration_minutes' => $this->duration_minutes,
            'location_text' => $this->when($manager || $this->interview_mode === 'on_site', $this->location),
            'location' => $this->when($manager || $this->interview_mode === 'on_site', $this->location),
            'meeting_link' => $this->when($manager || $this->interview_mode === 'online', $this->meeting_link),
            'candidate_message' => $this->candidate_message,
            'candidate_confirmation_status' => $this->confirmed_at !== null ? 'confirmed' : 'pending',
            'candidate_attendance_status' => $this->candidate_attendance_status,
            'scheduled_by_user_id' => $this->when($manager, $this->scheduled_by_user_id),
            'confirmed_by_user_id' => $this->when($manager, $this->confirmed_by_user_id),
            'completed_by_user_id' => $this->when($manager, $this->completed_by_user_id),
            'cancelled_by_user_id' => $this->when($manager, $this->cancelled_by_user_id),
            'attendance_recorded_by_user_id' => $this->when($manager, $this->attendance_recorded_by_user_id),
            'internal_note' => $this->when($manager, $this->internal_note),
            'note' => $this->when($manager, $this->internal_note),
            'completion_note' => $this->when($manager, $this->completion_note),
            'cancellation_reason' => $this->when($manager, $this->cancellation_reason),
            'cancellation_message' => $this->cancellation_message,
            'interviewer_attendance_status' => $this->when($manager, $this->interviewer_attendance_status),
            'attendance_note' => $this->when($manager, $this->attendance_note),
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'attendance_recorded_at' => $this->attendance_recorded_at?->toISOString(),
            'state' => $this->status,
            'evaluation' => $this->when(
                $manager && $this->relationLoaded('evaluation'),
                fn () => new InterviewEvaluationResource($this->evaluation),
            ),
            'status_history' => $this->when($manager && $this->relationLoaded('statusHistory'), fn () => InterviewStatusHistoryResource::collection($this->statusHistory)),
            'schedule_history' => $this->when($manager && $this->relationLoaded('scheduleChanges'), fn () => InterviewScheduleChangeResource::collection($this->scheduleChanges)),
            'job_application' => JobApplicationResource::make($this->whenLoaded('jobApplication')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

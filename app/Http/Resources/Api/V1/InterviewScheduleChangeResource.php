<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewScheduleChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'previous_start_at' => $this->previous_start_at?->toISOString(),
            'previous_end_at' => $this->previous_end_at?->toISOString(),
            'new_start_at' => $this->new_start_at?->toISOString(),
            'new_end_at' => $this->new_end_at?->toISOString(),
            'previous_mode' => $this->previous_mode,
            'new_mode' => $this->new_mode,
            'previous_meeting_link' => $this->previous_meeting_link,
            'new_meeting_link' => $this->new_meeting_link,
            'previous_location_text' => $this->previous_location_text,
            'new_location_text' => $this->new_location_text,
            'reason' => $this->reason,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy === null ? null : ['id' => $this->changedBy->id, 'name' => $this->changedBy->name, 'role' => $this->changedBy->role?->value]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

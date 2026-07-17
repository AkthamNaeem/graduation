<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy === null ? null : ['id' => $this->changedBy->id, 'name' => $this->changedBy->name, 'role' => $this->changedBy->role?->value]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

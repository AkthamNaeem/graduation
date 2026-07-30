<?php

namespace App\Http\Resources\Api\V1;

use App\Support\LocalizedValue;
use App\Support\SystemGeneratedText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InterviewStatusHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'interview_id' => $this->interview_id,
            'from_status' => LocalizedValue::make($this->from_status, 'interview_statuses'),
            'to_status' => LocalizedValue::make($this->to_status, 'interview_statuses'),
            'reason' => SystemGeneratedText::resolve($this->reason),
            'metadata' => $this->metadata,
            'changed_by' => $this->whenLoaded('changedBy', fn () => $this->changedBy === null ? null : [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
                'role' => LocalizedValue::make($this->changedBy->role, 'user_roles'),
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApplicationTestAssignmentDeadlineChange */
class TestAssignmentDeadlineChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'previous_deadline_at' => $this->previous_deadline_at?->toISOString(),
            'new_deadline_at' => $this->new_deadline_at?->toISOString(),
            'reason' => $this->reason,
            'changed_by' => $this->changedBy === null ? null : [
                'id' => $this->changedBy->id,
                'name' => $this->changedBy->name,
            ],
            'changed_at' => $this->created_at?->toISOString(),
        ];
    }
}

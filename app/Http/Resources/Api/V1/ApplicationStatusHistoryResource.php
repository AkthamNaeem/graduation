<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApplicationStatusHistory */
class ApplicationStatusHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'from_application_status_id' => $this->from_application_status_id,
            'to_application_status_id' => $this->to_application_status_id,
            'changed_by_user_id' => $this->changed_by_user_id,
            'note' => $this->note,
            'from_status' => ApplicationStatusResource::make($this->whenLoaded('fromStatus')),
            'to_status' => ApplicationStatusResource::make($this->whenLoaded('toStatus')),
            'changed_by' => UserResource::make($this->whenLoaded('changedBy')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

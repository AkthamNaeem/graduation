<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApplicationStatusHistory */
class ApplicationStatusHistoryResource extends JsonResource
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
            'from_status' => ApplicationStatusResource::make($this->whenLoaded('fromStatus')),
            'to_status' => ApplicationStatusResource::make($this->whenLoaded('toStatus')),
            'from_application_status_id' => $this->when($manager, $this->from_application_status_id),
            'to_application_status_id' => $this->when($manager, $this->to_application_status_id),
            'changed_by_user_id' => $this->when($manager, $this->changed_by_user_id),
            'note' => $this->when($manager, $this->note),
            'changed_by' => $this->when(
                $manager && $this->relationLoaded('changedBy'),
                fn (): ?array => $this->changedBy === null ? null : [
                    'id' => $this->changedBy->id,
                    'name' => $this->changedBy->name,
                    'role' => $this->changedBy->role?->value,
                ],
            ),
            'changed_at' => $this->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->when($manager, $this->updated_at?->toISOString()),
        ];
    }
}

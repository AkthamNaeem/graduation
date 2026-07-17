<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationInformationRequestResource extends JsonResource
{
    use ResolvesResourceViewer;

    public function toArray(Request $request): array
    {
        $manager = $this->viewerIsManager($request);

        return [
            'id' => $this->id,
            'job_application_id' => $this->job_application_id,
            'message' => $this->message,
            'requested_items' => ApplicationInformationRequestItemResource::collection($this->whenLoaded('items')),
            'due_at' => $this->due_at?->toISOString(),
            'status' => $this->status?->value,
            'is_expired' => $this->isExpired(),
            'can_respond' => ! $manager && $this->canBeRespondedTo(),
            'previous_application_status' => $this->when($manager, $this->previous_application_status),
            'requested_by' => $this->when($manager && $this->relationLoaded('requestedBy'), fn () => $this->requestedBy === null ? null : ['id' => $this->requestedBy->id, 'name' => $this->requestedBy->name]),
            'responded_at' => $this->responded_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancelled_by' => $this->when($manager && $this->relationLoaded('cancelledBy'), fn () => $this->cancelledBy === null ? null : ['id' => $this->cancelledBy->id, 'name' => $this->cancelledBy->name]),
            'response' => ApplicationInformationResponseResource::make($this->whenLoaded('response')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

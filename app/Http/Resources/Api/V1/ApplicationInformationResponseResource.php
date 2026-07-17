<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesResourceViewer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationInformationResponseResource extends JsonResource
{
    use ResolvesResourceViewer;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'submitted_at' => $this->submitted_at?->toISOString(),
            'submitted_by' => $this->when($this->viewerIsManager($request) && $this->relationLoaded('submittedBy'), fn () => $this->submittedBy === null ? null : ['id' => $this->submittedBy->id, 'name' => $this->submittedBy->name]),
            'attachments' => ApplicationInformationResponseAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

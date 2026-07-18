<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ApplicationInternalNoteRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ApplicationInternalNoteRevision */
class ApplicationInternalNoteRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'body' => $this->body,
            'edited_by' => ApplicationInternalNoteAuthorResource::make($this->whenLoaded('editedBy')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}

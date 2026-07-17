<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationInformationResponseAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'original_name' => $this->original_name, 'mime_type' => $this->mime_type, 'extension' => $this->extension, 'size_bytes' => $this->size_bytes, 'download_available' => true, 'created_at' => $this->created_at?->toISOString()];
    }
}

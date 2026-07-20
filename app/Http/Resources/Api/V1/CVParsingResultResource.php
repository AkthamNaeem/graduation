<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CVParsingResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CVParsingResult */
class CVParsingResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cv_file_id' => $this->cv_file_id,
            'raw_text' => $this->raw_text,
            'parsed_json' => $this->parsed_json,
            'reviewed_json' => $this->reviewed_json,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

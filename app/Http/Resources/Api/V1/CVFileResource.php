<?php

namespace App\Http\Resources\Api\V1;

use App\Models\JobSeekerProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CVFile */
class CVFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cacheKey = 'candidate_primary_cv_file_id';
        if (! $request->attributes->has($cacheKey)) {
            $request->attributes->set($cacheKey, JobSeekerProfile::query()
                ->where('user_id', $request->user()?->id)
                ->value('primary_cv_file_id'));
        }
        $primaryId = $request->attributes->get($cacheKey);
        $usable = $this->isUsableForApplication();

        return [
            'id' => $this->id,
            'version_label' => $this->version_label,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'parsing_status' => $this->status,
            'status' => $this->status,
            'is_primary' => $primaryId === $this->id,
            'is_archived' => $this->archived_at !== null,
            'can_set_primary' => $this->archived_at === null && $usable && $primaryId !== $this->id,
            'can_archive' => $this->archived_at === null,
            'can_restore' => $this->archived_at !== null,
            'can_use_for_application' => $usable,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'parsing_result' => CVParsingResultResource::make($this->whenLoaded('parsingResult')),
            'parsed_at' => $this->relationLoaded('parsingResult') ? $this->parsingResult?->created_at?->toISOString() : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CVFile */
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
            'parsing_status' => LocalizedValue::make($this->status, 'cv_parsing_statuses'),
            'status' => LocalizedValue::make($this->status, 'cv_parsing_statuses'),
            'review_mode' => LocalizedValue::make($this->review_mode, 'cv_review_modes'),
            'review_status' => LocalizedValue::make($this->review_status, 'cv_review_statuses'),
            'next_action' => LocalizedValue::make($this->nextAction(), 'cv_next_actions'),
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

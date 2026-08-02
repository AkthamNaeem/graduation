<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\JobSkillRequirementType;
use App\Models\Skill;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** @mixin Skill */
class SkillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon_url' => $this->icon_path === null ? null : Storage::disk('public')->url($this->icon_path),
            'requirement_type' => $this->whenPivotLoaded(
                'job_posting_skills',
                fn () => LocalizedValue::make($this->pivot->requirement_type instanceof JobSkillRequirementType
                    ? $this->pivot->requirement_type->canonicalValue()
                    : JobSkillRequirementType::normalize((string) $this->pivot->requirement_type)?->value, 'job_skill_requirement_types'),
            ),
            'weight' => $this->whenPivotLoaded('job_posting_skills', fn () => (int) $this->pivot->weight),
            'source_type' => $this->whenPivotLoaded(
                'job_seeker_skills',
                fn () => LocalizedValue::make($this->pivot->source_type, 'profile_source_types'),
            ),
            'source_cv_file_id' => $this->whenPivotLoaded('job_seeker_skills', fn () => $this->pivot->source_cv_file_id),
            'user_verified_at' => $this->whenPivotLoaded('job_seeker_skills', fn () => $this->pivot->user_verified_at?->toISOString()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

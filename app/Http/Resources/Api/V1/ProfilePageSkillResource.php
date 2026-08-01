<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Skill;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Skill */
class ProfilePageSkillResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'source_type' => $this->whenPivotLoaded(
                'job_seeker_skills',
                fn () => LocalizedValue::make($this->pivot->source_type, 'profile_source_types'),
            ),
            'user_verified_at' => $this->whenPivotLoaded(
                'job_seeker_skills',
                fn () => $this->pivot->user_verified_at?->toISOString(),
            ),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Experience;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Experience */
class ExperienceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'job_seeker_profile_id' => $this->job_seeker_profile_id,
            'title' => $this->title,
            'company_name' => $this->company_name,
            'location' => $this->location,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_current' => $this->is_current,
            'description' => $this->description,
            'source_type' => LocalizedValue::make($this->source_type, 'profile_source_types'),
            'source_cv_file_id' => $this->source_cv_file_id,
            'user_verified_at' => $this->user_verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

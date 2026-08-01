<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Experience;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Experience */
class ProfilePageExperienceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'company_name' => $this->company_name,
            'location' => $this->location,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_current' => $this->is_current,
            'description' => $this->description,
            'source_type' => LocalizedValue::make($this->source_type, 'profile_source_types'),
            'user_verified_at' => $this->user_verified_at?->toISOString(),
        ];
    }
}

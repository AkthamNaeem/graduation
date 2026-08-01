<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Education;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Education */
class ProfilePageEducationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'institution' => $this->institution,
            'degree' => $this->degree,
            'field_of_study' => $this->field_of_study,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'description' => $this->description,
            'source_type' => LocalizedValue::make($this->source_type, 'profile_source_types'),
            'user_verified_at' => $this->user_verified_at?->toISOString(),
        ];
    }
}

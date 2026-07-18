<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProfileChangeSuggestion */
class ProfileChangeSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'cv_file_id' => $this->cv_file_id,
            'job_seeker_profile_id' => $this->job_seeker_profile_id,
            'entity_type' => $this->entity_type,
            'suggestion_type' => $this->suggestion_type,
            'status' => $this->status,
            'source' => $this->source,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'user_edited_value' => $this->user_edited_value,
            'confidence_score' => $this->confidence_score,
            'reason' => $this->reason,
            'can_apply' => $this->status === \App\Models\ProfileChangeSuggestion::STATUS_ACCEPTED
                && $this->cvFile?->archived_at === null,
            'applied_at' => $this->applied_at?->toISOString(),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

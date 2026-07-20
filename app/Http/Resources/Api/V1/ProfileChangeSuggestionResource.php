<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CVFile;
use App\Models\ProfileChangeSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProfileChangeSuggestion */
class ProfileChangeSuggestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reviewIsMutable = $this->cvFile?->archived_at === null
            && $this->cvFile?->confirmed_at === null
            && $this->cvFile?->review_status !== CVFile::REVIEW_STATUS_APPLIED;

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
            'can_accept' => $this->suggestion_type !== ProfileChangeSuggestion::TYPE_IGNORE
                && $this->status !== ProfileChangeSuggestion::STATUS_APPLIED
                && $reviewIsMutable,
            'can_reject' => $this->suggestion_type !== ProfileChangeSuggestion::TYPE_IGNORE
                && $this->status !== ProfileChangeSuggestion::STATUS_APPLIED
                && $reviewIsMutable,
            'can_edit' => $this->suggestion_type !== ProfileChangeSuggestion::TYPE_IGNORE
                && $this->status !== ProfileChangeSuggestion::STATUS_APPLIED
                && $reviewIsMutable,
            'can_apply' => $this->suggestion_type !== ProfileChangeSuggestion::TYPE_IGNORE
                && $this->status === ProfileChangeSuggestion::STATUS_ACCEPTED
                && $this->cvFile?->review_status === CVFile::REVIEW_STATUS_READY_TO_APPLY
                && $reviewIsMutable,
            'is_actionable' => $this->suggestion_type !== ProfileChangeSuggestion::TYPE_IGNORE,
            'display_group' => $this->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE ? 'matched_items' : $this->entity_type,
            'applied_at' => $this->applied_at?->toISOString(),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

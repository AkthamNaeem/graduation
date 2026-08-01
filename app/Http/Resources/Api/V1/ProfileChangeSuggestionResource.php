<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CVFile;
use App\Models\ProfileChangeSuggestion;
use App\Support\LocalizedValue;
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
            'cv_file_id' => $this->cv_file_id,
            'entity_type' => LocalizedValue::make($this->entity_type, 'profile_entity_types'),
            'suggestion_type' => LocalizedValue::make($this->suggestion_type, 'profile_suggestion_types'),
            'status' => LocalizedValue::make($this->status, 'profile_suggestion_statuses'),
            'source' => LocalizedValue::make($this->source, 'profile_suggestion_sources'),
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'user_edited_value' => $this->user_edited_value,
            'current_value' => $this->old_value,
            'proposed_value' => $this->new_value,
            'editable_value' => $this->user_edited_value,
            'default_decision' => $this->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE
                ? 'ignore'
                : ($this->suggestion_type === ProfileChangeSuggestion::TYPE_ADD ? 'accept_add' : 'keep_current'),
            'selected_decision' => $this->selectedDecision(),
            'allowed_decisions' => $this->allowedDecisions(),
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
            'display_group' => LocalizedValue::make(
                $this->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE ? 'matched_items' : $this->entity_type,
                'profile_suggestion_display_groups',
            ),
            'applied_at' => $this->applied_at?->toISOString(),
            'decided_at' => $this->decided_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function selectedDecision(): ?string
    {
        if ($this->suggestion_type === ProfileChangeSuggestion::TYPE_IGNORE) {
            return 'ignore';
        }
        if ($this->status === ProfileChangeSuggestion::STATUS_REJECTED) {
            return 'keep_current';
        }
        if (! in_array($this->status, [ProfileChangeSuggestion::STATUS_ACCEPTED, ProfileChangeSuggestion::STATUS_APPLIED], true)) {
            return null;
        }
        if ($this->user_edited_value !== null) {
            return 'edit';
        }

        return match ($this->suggestion_type) {
            ProfileChangeSuggestion::TYPE_ADD => 'accept_add',
            ProfileChangeSuggestion::TYPE_UPDATE => 'accept_update',
            ProfileChangeSuggestion::TYPE_MERGE => 'accept_merge',
            default => 'ignore',
        };
    }

    /** @return list<string> */
    private function allowedDecisions(): array
    {
        return match ($this->suggestion_type) {
            ProfileChangeSuggestion::TYPE_ADD => ['accept_add', 'ignore', 'edit'],
            ProfileChangeSuggestion::TYPE_UPDATE => ['accept_update', 'keep_current', 'edit'],
            ProfileChangeSuggestion::TYPE_MERGE => ['accept_merge', 'keep_current', 'edit'],
            default => ['ignore'],
        };
    }
}

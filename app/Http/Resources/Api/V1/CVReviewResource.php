<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CVFile;
use App\Models\ProfileChangeSuggestion;
use App\Services\CV\CVFinalDraftChangeSummary;
use App\Services\CV\CVStageResolver;
use App\Support\LocalizedValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CVFile */
class CVReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $initialDraft = $this->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT
            && $this->review_status === CVFile::REVIEW_STATUS_DRAFT
            && $this->archived_at === null
            && $this->confirmed_at === null;
        $stage = app(CVStageResolver::class)->resolve($this->resource);
        $suggestions = $this->relationLoaded('profileChangeSuggestions')
            ? $this->profileChangeSuggestions
            : collect();
        $actionable = $suggestions->where('suggestion_type', '!=', ProfileChangeSuggestion::TYPE_IGNORE);
        $resolved = $actionable->where('status', '!=', ProfileChangeSuggestion::STATUS_PENDING)->count();
        $ready = $this->review_status === CVFile::REVIEW_STATUS_READY_TO_APPLY
            && $this->archived_at === null
            && $this->cancelled_at === null
            && $this->confirmed_at === null;
        $draft = $this->parsingResult?->reviewed_json;
        $changeSummary = app(CVFinalDraftChangeSummary::class)->summarize(
            $this->parsingResult?->comparison_base_json,
            is_array($draft) ? $draft : null,
            $suggestions->where('suggestion_type', ProfileChangeSuggestion::TYPE_MERGE)
                ->where('status', ProfileChangeSuggestion::STATUS_ACCEPTED)
                ->count(),
        );

        return [
            'cv_file_id' => $this->id,
            'operation' => [
                'key' => $this->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT ? 'initial_upload' : 'update',
                'label' => __('profile.cv_contract.operations.'.($this->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT ? 'initial_upload' : 'update')),
            ],
            'stage' => [
                'key' => $stage->value,
                'label' => __("profile.cv_contract.stages.{$stage->value}"),
            ],
            'parsing_status' => LocalizedValue::make($this->status, 'cv_parsing_statuses'),
            'review_mode' => LocalizedValue::make($this->review_mode, 'cv_review_modes'),
            'review_status' => LocalizedValue::make($this->review_status, 'cv_review_statuses'),
            'next_action' => LocalizedValue::make($this->nextAction(), 'cv_next_actions'),
            'can_edit_draft' => $initialDraft || $ready,
            'can_generate_suggestions' => $this->review_mode === CVFile::REVIEW_MODE_PROFILE_SYNC
                && $this->review_status === CVFile::REVIEW_STATUS_COMPARISON_PENDING
                && $this->archived_at === null,
            'can_apply_suggestions' => $ready,
            'can_confirm' => $ready && is_array($draft),
            'editable_sections' => ['profile', 'experience', 'education', 'skills'],
            'read_only_sections' => ['identity', 'languages', 'certifications', 'personal_information'],
            'draft' => $draft,
            'reviewed_json' => $draft,
            'final_profile' => $ready ? $draft : null,
            'validation_summary' => [
                'is_valid' => is_array($draft),
                'errors' => is_array($draft) ? [] : [__('domain_errors.CV_FINAL_DRAFT_INVALID')],
            ],
            'comparison_summary' => [
                'total' => $suggestions->count(),
                'add' => $suggestions->where('suggestion_type', ProfileChangeSuggestion::TYPE_ADD)->count(),
                'update' => $suggestions->where('suggestion_type', ProfileChangeSuggestion::TYPE_UPDATE)->count(),
                'merge' => $suggestions->where('suggestion_type', ProfileChangeSuggestion::TYPE_MERGE)->count(),
                'ignore' => $suggestions->where('suggestion_type', ProfileChangeSuggestion::TYPE_IGNORE)->count(),
                'resolved' => $resolved,
                'unresolved' => max(0, $actionable->count() - $resolved),
            ],
            'change_summary' => $changeSummary,
            'allowed_actions' => $ready
                ? ['edit_final_draft', 'confirm', 'cancel']
                : ($initialDraft ? ['update_draft', 'continue_to_confirmation', 'cancel'] : ['review_suggestions', 'cancel']),
            'reviewed_at' => $this->parsingResult?->reviewed_at?->toISOString(),
        ];
    }
}

<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CVFile;
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

        return [
            'cv_file_id' => $this->id,
            'parsing_status' => LocalizedValue::make($this->status, 'cv_parsing_statuses'),
            'review_mode' => LocalizedValue::make($this->review_mode, 'cv_review_modes'),
            'review_status' => LocalizedValue::make($this->review_status, 'cv_review_statuses'),
            'next_action' => LocalizedValue::make($this->nextAction(), 'cv_next_actions'),
            'can_edit_draft' => $initialDraft,
            'can_generate_suggestions' => $this->review_mode === CVFile::REVIEW_MODE_PROFILE_SYNC
                && $this->review_status === CVFile::REVIEW_STATUS_COMPARISON_PENDING
                && $this->archived_at === null,
            'can_apply_suggestions' => $this->review_mode === CVFile::REVIEW_MODE_PROFILE_SYNC
                && $this->review_status === CVFile::REVIEW_STATUS_READY_TO_APPLY
                && $this->archived_at === null,
            'editable_sections' => ['profile', 'experience', 'education', 'skills'],
            'read_only_sections' => ['identity', 'languages', 'certifications', 'personal_information'],
            'parsed_json' => $this->parsingResult?->parsed_json,
            'reviewed_json' => $this->parsingResult?->reviewed_json,
            'reviewed_at' => $this->parsingResult?->reviewed_at?->toISOString(),
        ];
    }
}

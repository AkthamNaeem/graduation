<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CVFile;
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
            'parsing_status' => $this->status,
            'review_mode' => $this->review_mode,
            'review_status' => $this->review_status,
            'next_action' => $this->nextAction(),
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

<?php

namespace App\Services\CV;

use App\Enums\CandidateCVStage;
use App\Models\CVFile;

class CVStageResolver
{
    public function resolve(CVFile $cv): CandidateCVStage
    {
        if ($cv->isConfirmedUsableForApplication()) {
            return CandidateCVStage::CONFIRMED;
        }

        if ($cv->status === 'failed') {
            return CandidateCVStage::FAILED;
        }

        if (in_array($cv->status, ['uploaded', 'processing'], true)) {
            return CandidateCVStage::PROCESSING;
        }

        if ($cv->status !== 'parsed') {
            return CandidateCVStage::PROCESSING;
        }

        if ($cv->review_status === CVFile::REVIEW_STATUS_READY_TO_APPLY
            || $cv->review_status === CVFile::REVIEW_STATUS_APPLIED) {
            return CandidateCVStage::FINAL_CONFIRMATION;
        }

        if ($cv->review_mode === CVFile::REVIEW_MODE_INITIAL_IMPORT
            && $cv->review_status === CVFile::REVIEW_STATUS_DRAFT) {
            return CandidateCVStage::FIRST_REVIEW;
        }

        if ($cv->review_mode === CVFile::REVIEW_MODE_PROFILE_SYNC
            && in_array($cv->review_status, [
                CVFile::REVIEW_STATUS_COMPARISON_PENDING,
                CVFile::REVIEW_STATUS_DECISIONS_PENDING,
            ], true)) {
            return CandidateCVStage::DIFFERENCES_REVIEW;
        }

        if ((int) ($cv->pending_suggestions_count ?? 0) > 0) {
            return CandidateCVStage::DIFFERENCES_REVIEW;
        }

        return CandidateCVStage::PROCESSING;
    }
}

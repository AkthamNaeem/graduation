<?php

namespace App\Services\CV;

use App\Enums\CandidateCVOperation;
use App\Enums\CandidateCVStage;
use App\Models\CVFile;
use App\Models\JobSeekerProfile;
use App\Models\User;

class CandidateCVStateResolver
{
    public function __construct(
        private readonly CurrentCVResolver $currentCVResolver,
        private readonly CVStageResolver $stageResolver,
    ) {}

    /**
     * @return array{current_cv: ?CVFile, pending_cv_update: ?array<string, mixed>}
     */
    public function resolve(User $user, JobSeekerProfile $profile): array
    {
        $current = $this->currentCVResolver->resolve($user, $profile);
        $pending = $profile->relationLoaded('latestUnconfirmedCVFile')
            ? $profile->latestUnconfirmedCVFile
            : null;

        if (! $pending instanceof CVFile
            || $pending->user_id !== $user->id
            || $pending->archived_at !== null
            || $pending->confirmed_at !== null
            || $pending->id === $current?->id) {
            $pending = null;
        }

        return [
            'current_cv' => $current,
            'pending_cv_update' => $pending instanceof CVFile
                ? $this->pendingState($pending, $current)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function pendingState(CVFile $pending, ?CVFile $current): array
    {
        $stage = $this->stageResolver->resolve($pending);

        return [
            'cv' => $pending,
            'operation' => $current instanceof CVFile
                ? CandidateCVOperation::UPDATE
                : CandidateCVOperation::INITIAL_UPLOAD,
            'stage' => $stage,
            'progress' => $this->progress($pending, $stage),
            'next_action' => $this->nextAction($pending, $stage),
            'allowed_actions' => $this->allowedActions($stage),
        ];
    }

    /** @return array<string, bool> */
    private function progress(CVFile $cv, CandidateCVStage $stage): array
    {
        return [
            'upload_completed' => true,
            'text_extracted' => $cv->relationLoaded('parsingResult') && $cv->parsingResult !== null,
            'parsing_completed' => $cv->status === 'parsed',
            'review_completed' => $stage === CandidateCVStage::FINAL_CONFIRMATION,
        ];
    }

    /** @return array<string, mixed>|null */
    private function nextAction(CVFile $cv, CandidateCVStage $stage): ?array
    {
        return match ($stage) {
            CandidateCVStage::PROCESSING => [
                'type' => 'wait_for_processing',
                'target' => ['type' => 'cv', 'id' => $cv->id],
                'is_actionable' => false,
            ],
            CandidateCVStage::FIRST_REVIEW => [
                'type' => 'review_extracted_cv',
                'target' => ['type' => 'cv_review', 'id' => $cv->id],
                'is_actionable' => true,
            ],
            CandidateCVStage::DIFFERENCES_REVIEW => [
                'type' => 'review_cv_changes',
                'target' => ['type' => 'cv_review', 'id' => $cv->id],
                'is_actionable' => true,
            ],
            CandidateCVStage::FINAL_CONFIRMATION => [
                'type' => 'confirm_cv_review',
                'target' => ['type' => 'cv_review', 'id' => $cv->id],
                'is_actionable' => true,
            ],
            CandidateCVStage::FAILED => [
                'type' => 'upload_cv',
                'target' => ['type' => 'cv_upload', 'id' => null],
                'is_actionable' => true,
            ],
            CandidateCVStage::CONFIRMED => null,
        };
    }

    /** @return list<string> */
    private function allowedActions(CandidateCVStage $stage): array
    {
        return match ($stage) {
            CandidateCVStage::PROCESSING,
            CandidateCVStage::FAILED => ['view_status'],
            CandidateCVStage::FIRST_REVIEW,
            CandidateCVStage::DIFFERENCES_REVIEW => ['review'],
            CandidateCVStage::FINAL_CONFIRMATION => ['confirm'],
            CandidateCVStage::CONFIRMED => [],
        };
    }
}

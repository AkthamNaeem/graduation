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
        private readonly CandidateCVOperationResolver $operationResolver,
        private readonly CVFileActionResolver $actionResolver,
    ) {}

    /**
     * @return array{cv: array<string, mixed>, current_cv: ?array<string, mixed>, pending_cv_update: ?array<string, mixed>}
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
            || $pending->cancelled_at !== null
            || $pending->confirmed_at !== null
            || $pending->id === $current?->id) {
            $pending = null;
        }

        $hasPending = $pending instanceof CVFile;

        $currentState = $current instanceof CVFile ? [
            'cv' => $current,
            'allowed_actions' => $this->actionResolver->current($current, $hasPending),
        ] : null;
        $pendingState = $pending instanceof CVFile
            ? $this->pendingState($user, $profile, $pending)
            : null;

        return [
            'cv' => $this->logicalState($currentState, $pendingState),
            'current_cv' => $currentState,
            'pending_cv_update' => $pendingState,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>|null  $pending
     * @return array<string, mixed>
     */
    private function logicalState(?array $current, ?array $pending): array
    {
        /** @var CandidateCVStage|null $pendingStage */
        $pendingStage = $pending['stage'] ?? null;
        $status = match ($pendingStage) {
            CandidateCVStage::PROCESSING => 'processing',
            CandidateCVStage::FIRST_REVIEW => 'review_required',
            CandidateCVStage::DIFFERENCES_REVIEW => 'suggestions_review_required',
            CandidateCVStage::FINAL_CONFIRMATION => 'ready_for_confirmation',
            CandidateCVStage::FAILED => 'failed',
            default => $current === null ? 'no_cv' : 'confirmed',
        };

        $actions = [];
        if ($current !== null) {
            $actions = ['preview_cv', 'download_cv'];
        }
        if ($pending !== null) {
            $actions[] = 'continue_cv_review';
        } elseif ($current !== null) {
            $actions[] = 'update_cv';
        } else {
            $actions[] = 'upload_cv';
        }

        return [
            'status' => $status,
            'is_ready' => $current !== null,
            'pending_update' => $pending,
            'allowed_actions' => $actions,
        ];
    }

    /** @return array<string, mixed> */
    private function pendingState(User $user, JobSeekerProfile $profile, CVFile $pending): array
    {
        $stage = $this->stageResolver->resolve($pending);

        return [
            'cv' => $pending,
            'operation' => match ($pending->review_mode) {
                CVFile::REVIEW_MODE_INITIAL_IMPORT => CandidateCVOperation::INITIAL_UPLOAD,
                CVFile::REVIEW_MODE_PROFILE_SYNC => CandidateCVOperation::UPDATE,
                default => $this->operationResolver->resolve($user, $profile),
            },
            'stage' => $stage,
            'progress' => $this->progress($pending, $stage),
            'next_action' => $this->nextAction($pending, $stage),
            'allowed_actions' => $this->actionResolver->pending($pending, $stage),
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
}

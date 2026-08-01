<?php

namespace App\Services\CV;

use App\Enums\CandidateCVStage;
use App\Models\CVFile;

class CVFileActionResolver
{
    public function __construct(
        private readonly CVFileAccessService $fileAccess,
    ) {}

    /** @return list<string> */
    public function current(CVFile $cvFile, bool $hasPendingWorkflow): array
    {
        $capabilities = $this->fileAccess->capabilities($cvFile);
        $actions = [];

        if ($capabilities['preview']) {
            $actions[] = 'preview';
        }
        if ($capabilities['download']) {
            $actions[] = 'download';
        }
        if (! $hasPendingWorkflow) {
            $actions[] = 'update';
        }

        return $actions;
    }

    /** @return list<string> */
    public function pending(CVFile $cvFile, CandidateCVStage $stage): array
    {
        $capabilities = $this->fileAccess->capabilities($cvFile);
        $actions = [];

        if ($stage !== CandidateCVStage::FAILED && $capabilities['preview']) {
            $actions[] = 'preview';
        }
        if ($capabilities['download']) {
            $actions[] = 'download';
        }

        return [
            ...$actions,
            ...match ($stage) {
                CandidateCVStage::PROCESSING => ['view_status', 'cancel'],
                CandidateCVStage::FIRST_REVIEW,
                CandidateCVStage::DIFFERENCES_REVIEW => ['review', 'cancel'],
                CandidateCVStage::FINAL_CONFIRMATION => ['review', 'confirm', 'cancel'],
                CandidateCVStage::FAILED => ['cancel'],
                CandidateCVStage::CONFIRMED => [],
            },
        ];
    }
}

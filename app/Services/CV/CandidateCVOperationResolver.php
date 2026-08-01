<?php

namespace App\Services\CV;

use App\Enums\CandidateCVOperation;
use App\Models\JobSeekerProfile;
use App\Models\User;

class CandidateCVOperationResolver
{
    public function __construct(
        private readonly CurrentCVResolver $currentCVResolver,
        private readonly ProfileDataStateService $profileDataStateService,
    ) {}

    public function resolve(User $user, JobSeekerProfile $profile): CandidateCVOperation
    {
        if ($this->currentCVResolver->resolve($user, $profile) !== null
            || $this->profileDataStateService->hasMeaningfulData($profile)) {
            return CandidateCVOperation::UPDATE;
        }

        return CandidateCVOperation::INITIAL_UPLOAD;
    }
}

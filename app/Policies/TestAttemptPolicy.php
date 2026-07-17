<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TestAttempt;
use App\Models\User;

class TestAttemptPolicy
{
    public function viewAnswers(User $user, TestAttempt $testAttempt): bool
    {
        if ($user->role === UserRole::ADMIN) {
            return true;
        }

        if ($user->role === UserRole::JOB_SEEKER) {
            return $user->jobSeekerProfile?->id
                === $testAttempt->applicationTestAssignment->jobApplication->job_seeker_profile_id;
        }

        return $user->role === UserRole::EMPLOYER
            && $user->employerProfile()
                ->where('company_id', $testAttempt->applicationTestAssignment->jobApplication->jobPosting->company_id)
                ->exists();
    }

    public function manageAnswers(User $user, TestAttempt $testAttempt): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && $user->jobSeekerProfile?->id
                === $testAttempt->applicationTestAssignment->jobApplication->job_seeker_profile_id;
    }

    public function viewResult(User $user, TestAttempt $testAttempt): bool
    {
        return $this->viewAnswers($user, $testAttempt);
    }

    public function downloadAnswer(User $user, TestAttempt $testAttempt): bool
    {
        return $this->viewAnswers($user, $testAttempt);
    }

    public function evaluate(User $user, TestAttempt $testAttempt): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $user->employerProfile()
                ->where('company_id', $testAttempt->applicationTestAssignment->jobApplication->jobPosting->company_id)
                ->exists();
    }

    public function manageManualGradings(User $user, TestAttempt $testAttempt): bool
    {
        if ($user->role === UserRole::ADMIN) {
            return true;
        }

        return $user->role === UserRole::EMPLOYER
            && $user->employerProfile()
                ->where('company_id', $testAttempt->applicationTestAssignment->jobApplication->jobPosting->company_id)
                ->exists();
    }
}

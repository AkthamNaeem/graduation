<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Enums\UserRole;
use App\Models\TestAttempt;
use App\Models\User;
use App\Services\CompanyPermissionService;

class TestAttemptPolicy
{
    public function __construct(
        private readonly CompanyPermissionService $permissions,
    ) {}

    public function viewQuestions(User $user, TestAttempt $testAttempt): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && (int) ($user->jobSeekerProfile?->id ?? 0)
                === (int) $testAttempt->applicationTestAssignment->jobApplication->job_seeker_profile_id;
    }

    public function viewAnswers(User $user, TestAttempt $testAttempt): bool
    {
        if ($user->role === UserRole::JOB_SEEKER) {
            return $user->jobSeekerProfile?->id
                === $testAttempt->applicationTestAssignment->jobApplication->job_seeker_profile_id;
        }

        return $this->permissions->can(
            $user,
            CompanyPermission::VIEW_TESTS,
            $testAttempt->applicationTestAssignment->jobApplication->jobPosting->company_id,
        );
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
        return $this->permissions->can(
            $user,
            CompanyPermission::MANAGE_TESTS,
            $testAttempt->applicationTestAssignment->jobApplication->jobPosting->company_id,
        );
    }

    public function manageManualGradings(User $user, TestAttempt $testAttempt): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::GRADE_TESTS,
            $testAttempt->applicationTestAssignment->jobApplication->jobPosting->company_id,
        );
    }
}

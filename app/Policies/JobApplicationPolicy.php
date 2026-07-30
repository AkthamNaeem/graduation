<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\CompanyPermissionService;

class JobApplicationPolicy
{
    public function __construct(
        private readonly CompanyPermissionService $permissions,
    ) {}

    public function view(User $user, JobApplication $jobApplication): bool
    {
        if ($user->role === UserRole::JOB_SEEKER) {
            return $user->jobSeekerProfile?->id === $jobApplication->job_seeker_profile_id;
        }

        return $this->permissions->can(
            $user,
            CompanyPermission::VIEW_APPLICATIONS,
            $jobApplication->jobPosting->company_id,
        );
    }

    public function withdraw(User $user, JobApplication $jobApplication): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && $user->jobSeekerProfile?->id === $jobApplication->job_seeker_profile_id;
    }

    public function changeStatus(User $user, JobApplication $jobApplication): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::MANAGE_APPLICATIONS,
            $jobApplication->jobPosting->company_id,
        );
    }

    public function viewJobApplications(User $user, JobPosting $jobPosting): bool
    {
        return $this->permissions->can($user, CompanyPermission::VIEW_APPLICATIONS, $jobPosting->company_id);
    }
}

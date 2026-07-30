<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\CompanyPermissionService;

class JobPostingPolicy
{
    public function __construct(
        private readonly CompanyPermissionService $permissions,
    ) {}

    public function view(?User $user, JobPosting $jobPosting): bool
    {
        if ($jobPosting->status === 'open') {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $this->permissions->can($user, CompanyPermission::VIEW_JOBS, $jobPosting->company_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can($user, CompanyPermission::MANAGE_JOBS);
    }

    public function update(User $user, JobPosting $jobPosting): bool
    {
        return $this->permissions->can($user, CompanyPermission::MANAGE_JOBS, $jobPosting->company_id);
    }

    public function delete(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }

    public function attachSkills(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }

    public function detachSkills(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }

    public function publish(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }

    public function close(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }

    public function viewJobApplications(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }

    public function manageScreeningQuestions(User $user, JobPosting $jobPosting): bool
    {
        return $this->update($user, $jobPosting);
    }
}

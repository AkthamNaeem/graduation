<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Enums\UserRole;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\CompanyPermissionService;

class InterviewPolicy
{
    public function __construct(
        private readonly CompanyPermissionService $permissions,
    ) {}

    public function createForApplication(User $user, JobApplication $jobApplication): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::MANAGE_INTERVIEWS,
            $jobApplication->jobPosting->company_id,
        );
    }

    public function viewForApplication(User $user, JobApplication $jobApplication): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::VIEW_INTERVIEWS,
            $jobApplication->jobPosting->company_id,
        );
    }

    public function view(User $user, Interview $interview): bool
    {
        if ($user->role === UserRole::JOB_SEEKER) {
            return $user->jobSeekerProfile?->id === $interview->jobApplication->job_seeker_profile_id;
        }

        return $this->permissions->can(
            $user,
            CompanyPermission::VIEW_INTERVIEWS,
            $interview->jobApplication->jobPosting->company_id,
        );
    }

    public function update(User $user, Interview $interview): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::MANAGE_INTERVIEWS,
            $interview->jobApplication->jobPosting->company_id,
        );
    }

    public function confirm(User $user, Interview $interview): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && $user->jobSeekerProfile?->id === $interview->jobApplication->job_seeker_profile_id;
    }

    public function reschedule(User $user, Interview $interview): bool
    {
        return $this->update($user, $interview);
    }

    public function cancel(User $user, Interview $interview): bool
    {
        return $this->update($user, $interview);
    }

    public function manageAttendance(User $user, Interview $interview): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::EVALUATE_INTERVIEWS,
            $interview->jobApplication->jobPosting->company_id,
        );
    }

    public function markNoShow(User $user, Interview $interview): bool
    {
        return $this->update($user, $interview);
    }

    public function viewHistory(User $user, Interview $interview): bool
    {
        return $this->update($user, $interview);
    }

    public function delete(User $user, Interview $interview): bool
    {
        return $this->update($user, $interview);
    }

    public function complete(User $user, Interview $interview): bool
    {
        return $this->manageAttendance($user, $interview);
    }

    public function evaluate(User $user, Interview $interview): bool
    {
        return $this->manageAttendance($user, $interview);
    }

    public function joinVideo(User $user, Interview $interview): bool
    {
        if ($user->role === UserRole::JOB_SEEKER) {
            return $this->confirm($user, $interview);
        }

        return $user->role === UserRole::EMPLOYER && $this->view($user, $interview);
    }
}

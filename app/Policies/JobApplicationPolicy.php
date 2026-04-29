<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\User;

class JobApplicationPolicy
{
    public function view(User $user, JobApplication $jobApplication): bool
    {
        if ($user->role === UserRole::JOB_SEEKER) {
            return $user->jobSeekerProfile?->id === $jobApplication->job_seeker_profile_id;
        }

        if ($user->role !== UserRole::EMPLOYER) {
            return false;
        }

        return $this->belongsToCompany($user, $jobApplication->jobPosting->company_id);
    }

    public function withdraw(User $user, JobApplication $jobApplication): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && $user->jobSeekerProfile?->id === $jobApplication->job_seeker_profile_id;
    }

    public function changeStatus(User $user, JobApplication $jobApplication): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $jobApplication->jobPosting->company_id);
    }

    public function viewJobApplications(User $user, JobPosting $jobPosting): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $jobPosting->company_id);
    }

    private function belongsToCompany(User $user, int $companyId): bool
    {
        return $user->employerProfile()
            ->where('company_id', $companyId)
            ->exists();
    }
}

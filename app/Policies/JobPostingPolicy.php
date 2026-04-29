<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\JobPosting;
use App\Models\User;

class JobPostingPolicy
{
    public function view(?User $user, JobPosting $jobPosting): bool
    {
        if ($jobPosting->status === 'open') {
            return true;
        }

        if (! $user || $user->role !== UserRole::EMPLOYER) {
            return false;
        }

        return $this->belongsToCompany($user, $jobPosting);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $user->employerProfile()->exists();
    }

    public function update(User $user, JobPosting $jobPosting): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $jobPosting);
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

    private function belongsToCompany(User $user, JobPosting $jobPosting): bool
    {
        return $user->employerProfile()
            ->where('company_id', $jobPosting->company_id)
            ->exists();
    }
}

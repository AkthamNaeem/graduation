<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\User;

class InterviewPolicy
{
    public function createForApplication(User $user, JobApplication $jobApplication): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $jobApplication->jobPosting->company_id);
    }

    public function viewForApplication(User $user, JobApplication $jobApplication): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $jobApplication->jobPosting->company_id);
    }

    public function view(User $user, Interview $interview): bool
    {
        if ($user->role === UserRole::JOB_SEEKER) {
            return $user->jobSeekerProfile?->id === $interview->jobApplication->job_seeker_profile_id;
        }

        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $interview->jobApplication->jobPosting->company_id);
    }

    public function update(User $user, Interview $interview): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $interview->jobApplication->jobPosting->company_id);
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
        return $this->update($user, $interview);
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
        return $this->update($user, $interview);
    }

    public function evaluate(User $user, Interview $interview): bool
    {
        return $this->update($user, $interview);
    }

    private function belongsToCompany(User $user, int $companyId): bool
    {
        return $user->employerProfile()
            ->where('company_id', $companyId)
            ->exists();
    }
}

<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ApplicationTestAssignment;
use App\Models\JobApplication;
use App\Models\User;

class ApplicationTestAssignmentPolicy
{
    public function assign(User $user, JobApplication $jobApplication): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $jobApplication->jobPosting->company_id);
    }

    public function viewForApplication(User $user, JobApplication $jobApplication): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $this->belongsToCompany($user, $jobApplication->jobPosting->company_id);
    }

    public function start(User $user, ApplicationTestAssignment $assignment): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && $user->jobSeekerProfile?->id === $assignment->jobApplication->job_seeker_profile_id;
    }

    public function submit(User $user, ApplicationTestAssignment $assignment): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && $user->jobSeekerProfile?->id === $assignment->jobApplication->job_seeker_profile_id;
    }

    public function extendDeadline(User $user, ApplicationTestAssignment $assignment): bool
    {
        return $user->role === UserRole::ADMIN
            || ($user->role === UserRole::EMPLOYER
                && $this->belongsToCompany($user, $assignment->jobApplication->jobPosting->company_id));
    }

    public function viewDeadlineHistory(User $user, ApplicationTestAssignment $assignment): bool
    {
        return $this->extendDeadline($user, $assignment);
    }

    public function manageRetakes(User $user, ApplicationTestAssignment $assignment): bool
    {
        return $this->extendDeadline($user, $assignment);
    }

    public function viewSeries(User $user, ApplicationTestAssignment $assignment): bool
    {
        if ($user->role === UserRole::JOB_SEEKER) {
            return $user->jobSeekerProfile?->id === $assignment->jobApplication->job_seeker_profile_id;
        }

        return $this->manageRetakes($user, $assignment);
    }

    private function belongsToCompany(User $user, int $companyId): bool
    {
        return $user->employerProfile()
            ->where('company_id', $companyId)
            ->exists();
    }
}

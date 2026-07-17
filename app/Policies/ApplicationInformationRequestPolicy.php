<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ApplicationInformationRequest;
use App\Models\JobApplication;
use App\Models\User;

class ApplicationInformationRequestPolicy
{
    public function viewAnyForApplication(User $user, JobApplication $application): bool
    {
        return $this->canViewApplication($user, $application);
    }

    public function create(User $user, JobApplication $application): bool
    {
        return $user->role === UserRole::EMPLOYER && $this->employerOwns($user, $application);
    }

    public function view(User $user, ApplicationInformationRequest $request): bool
    {
        return $this->canViewApplication($user, $request->jobApplication);
    }

    public function update(User $user, ApplicationInformationRequest $request): bool
    {
        return $user->role === UserRole::EMPLOYER && $this->employerOwns($user, $request->jobApplication);
    }

    public function cancel(User $user, ApplicationInformationRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function respond(User $user, ApplicationInformationRequest $request): bool
    {
        return $user->role === UserRole::JOB_SEEKER
            && (int) ($user->jobSeekerProfile?->id ?? 0) === (int) $request->jobApplication->job_seeker_profile_id;
    }

    public function downloadAttachment(User $user, ApplicationInformationRequest $request): bool
    {
        return $this->view($user, $request);
    }

    private function canViewApplication(User $user, JobApplication $application): bool
    {
        return match ($user->role) {
            UserRole::JOB_SEEKER => (int) ($user->jobSeekerProfile?->id ?? 0) === (int) $application->job_seeker_profile_id,
            UserRole::EMPLOYER => $this->employerOwns($user, $application),
            default => false,
        };
    }

    private function employerOwns(User $user, JobApplication $application): bool
    {
        return $user->employerProfile()->where('company_id', $application->jobPosting->company_id)->exists();
    }
}

<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Enums\UserRole;
use App\Models\ApplicationInformationRequest;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\CompanyPermissionService;

class ApplicationInformationRequestPolicy
{
    public function __construct(
        private readonly CompanyPermissionService $permissions,
    ) {}

    public function viewAnyForApplication(User $user, JobApplication $application): bool
    {
        return $this->canViewApplication($user, $application);
    }

    public function create(User $user, JobApplication $application): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::MANAGE_APPLICATIONS,
            $application->jobPosting->company_id,
        );
    }

    public function view(User $user, ApplicationInformationRequest $request): bool
    {
        return $this->canViewApplication($user, $request->jobApplication);
    }

    public function update(User $user, ApplicationInformationRequest $request): bool
    {
        return $this->permissions->can(
            $user,
            CompanyPermission::MANAGE_APPLICATIONS,
            $request->jobApplication->jobPosting->company_id,
        );
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
            default => $this->permissions->can(
                $user,
                CompanyPermission::VIEW_APPLICATIONS,
                $application->jobPosting->company_id,
            ),
        };
    }
}

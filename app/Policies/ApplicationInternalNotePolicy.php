<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\ApplicationInternalNote;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\CompanyPermissionService;

class ApplicationInternalNotePolicy
{
    public function __construct(
        private readonly CompanyPermissionService $permissions,
    ) {}

    public function viewAnyForApplication(User $user, JobApplication $application): bool
    {
        return $this->sameCompanyEmployer($user, $application);
    }

    public function create(User $user, JobApplication $application): bool
    {
        return $this->sameCompanyEmployer($user, $application);
    }

    public function view(User $user, ApplicationInternalNote $note): bool
    {
        return $this->sameCompanyEmployer($user, $note->jobApplication);
    }

    public function update(User $user, ApplicationInternalNote $note): bool
    {
        return $this->view($user, $note) && $note->author_user_id === $user->id;
    }

    public function delete(User $user, ApplicationInternalNote $note): bool
    {
        return $this->update($user, $note);
    }

    public function viewRevisions(User $user, ApplicationInternalNote $note): bool
    {
        return $this->view($user, $note);
    }

    private function sameCompanyEmployer(User $user, JobApplication $application): bool
    {
        $companyId = $application->jobPosting?->company_id
            ?? $application->jobPosting()->value('company_id');

        return $companyId !== null
            && $this->permissions->can($user, CompanyPermission::MANAGE_INTERNAL_NOTES, $companyId);
    }
}

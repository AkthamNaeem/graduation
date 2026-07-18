<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ApplicationInternalNote;
use App\Models\JobApplication;
use App\Models\User;

class ApplicationInternalNotePolicy
{
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
        if ($user->role !== UserRole::EMPLOYER) {
            return false;
        }

        $companyId = $application->jobPosting?->company_id
            ?? $application->jobPosting()->value('company_id');

        return $companyId !== null
            && $user->employerProfile()->where('company_id', $companyId)->exists();
    }
}

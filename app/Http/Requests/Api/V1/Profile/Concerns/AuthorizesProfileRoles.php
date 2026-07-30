<?php

namespace App\Http\Requests\Api\V1\Profile\Concerns;

use App\Enums\CompanyMembershipStatus;
use App\Enums\UserRole;

trait AuthorizesProfileRoles
{
    protected function isJobSeeker(): bool
    {
        return $this->user()?->role === UserRole::JOB_SEEKER;
    }

    protected function isEmployer(): bool
    {
        $user = $this->user();

        return $user?->role === UserRole::EMPLOYER
            && $user->employerProfile?->membership_status === CompanyMembershipStatus::ACTIVE;
    }
}

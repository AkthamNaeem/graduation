<?php

namespace App\Http\Requests\Api\V1\Profile\Concerns;

use App\Enums\UserRole;

trait AuthorizesProfileRoles
{
    protected function isJobSeeker(): bool
    {
        return $this->user()?->role === UserRole::JOB_SEEKER;
    }

    protected function isEmployer(): bool
    {
        return $this->user()?->role === UserRole::EMPLOYER;
    }
}

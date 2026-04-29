<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\TestAttempt;
use App\Models\User;

class TestAttemptPolicy
{
    public function evaluate(User $user, TestAttempt $testAttempt): bool
    {
        return $user->role === UserRole::EMPLOYER
            && $user->employerProfile()
                ->where('company_id', $testAttempt->applicationTestAssignment->jobApplication->jobPosting->company_id)
                ->exists();
    }
}

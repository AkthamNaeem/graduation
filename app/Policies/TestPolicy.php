<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Test;
use App\Models\User;

class TestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::EMPLOYER || $user->role === UserRole::ADMIN;
    }

    public function view(User $user, Test $test): bool
    {
        if ($user->role === UserRole::ADMIN) {
            return true;
        }

        return $user->role === UserRole::EMPLOYER
            && $this->belongsToEmployerCompany($user, $test);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::ADMIN
            || ($user->role === UserRole::EMPLOYER && $user->employerProfile()->exists());
    }

    public function update(User $user, Test $test): bool
    {
        return $user->role === UserRole::ADMIN
            || ($user->role === UserRole::EMPLOYER && $this->belongsToEmployerCompany($user, $test));
    }

    public function delete(User $user, Test $test): bool
    {
        return $this->update($user, $test);
    }

    public function manageQuestions(User $user, Test $test): bool
    {
        return $this->update($user, $test);
    }

    private function belongsToEmployerCompany(User $user, Test $test): bool
    {
        return $test->company_id !== null
            && $user->employerProfile()->where('company_id', $test->company_id)->exists();
    }
}

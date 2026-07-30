<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\Test;
use App\Models\User;
use App\Services\CompanyPermissionService;

class TestPolicy
{
    public function __construct(
        private readonly CompanyPermissionService $permissions,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissions->can($user, CompanyPermission::VIEW_TESTS);
    }

    public function view(User $user, Test $test): bool
    {
        return $this->permissions->can($user, CompanyPermission::VIEW_TESTS, $test->company_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can($user, CompanyPermission::MANAGE_TESTS);
    }

    public function update(User $user, Test $test): bool
    {
        return $this->permissions->can($user, CompanyPermission::MANAGE_TESTS, $test->company_id);
    }

    public function delete(User $user, Test $test): bool
    {
        return $this->update($user, $test);
    }

    public function manageQuestions(User $user, Test $test): bool
    {
        return $this->update($user, $test);
    }
}

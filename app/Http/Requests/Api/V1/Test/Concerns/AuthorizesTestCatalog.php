<?php

namespace App\Http\Requests\Api\V1\Test\Concerns;

use App\Enums\UserRole;
use App\Models\Test;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

trait AuthorizesTestCatalog
{
    protected function authenticatedUser(): ?User
    {
        $token = $this->bearerToken();

        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User
            ? $tokenable->withAccessToken($accessToken)
            : null;
    }

    protected function canReadTestCatalog(): bool
    {
        $user = $this->authenticatedUser();

        return $user instanceof User && $user->can('viewAny', Test::class);
    }

    protected function canManageTestCatalog(): bool
    {
        $role = $this->authenticatedUser()?->role;

        return $role === UserRole::EMPLOYER
            || $role === UserRole::ADMIN;
    }

    protected function canViewTest(Test $test): bool
    {
        return $this->authenticatedUser()?->can('view', $test) ?? false;
    }

    protected function canUpdateTest(Test $test): bool
    {
        return $this->authenticatedUser()?->can('update', $test) ?? false;
    }

    protected function canDeleteTest(Test $test): bool
    {
        return $this->authenticatedUser()?->can('delete', $test) ?? false;
    }
}

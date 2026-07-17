<?php

namespace App\Http\Requests\Api\V1\Test\Concerns;

use App\Enums\UserRole;
use App\Exceptions\TestContentAccessException;
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

        if (! $tokenable instanceof User) {
            return null;
        }

        $user = $tokenable->withAccessToken($accessToken);
        $this->setUserResolver(static fn (?string $guard = null): User => $user);

        return $user;
    }

    protected function canReadTestCatalog(): bool
    {
        $user = $this->authenticatedUser();

        if ($user?->role === UserRole::JOB_SEEKER) {
            throw new TestContentAccessException(
                'Test catalog access is not available for job seekers.',
                'TEST_CATALOG_FORBIDDEN',
                403,
            );
        }

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

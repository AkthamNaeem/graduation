<?php

namespace App\Http\Requests\Api\V1\Test\Concerns;

use App\Enums\UserRole;
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
        return $this->authenticatedUser() instanceof User;
    }

    protected function canManageTestCatalog(): bool
    {
        $role = $this->authenticatedUser()?->role;

        return $role === UserRole::EMPLOYER
            || $role === UserRole::ADMIN;
    }
}

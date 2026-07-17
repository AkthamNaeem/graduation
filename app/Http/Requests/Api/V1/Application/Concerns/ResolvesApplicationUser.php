<?php

namespace App\Http\Requests\Api\V1\Application\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

trait ResolvesApplicationUser
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

    protected function isJobSeekerUser(): bool
    {
        return $this->authenticatedUser()?->role === UserRole::JOB_SEEKER;
    }
}

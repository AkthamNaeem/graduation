<?php

namespace App\Http\Requests\Api\V1\JobPosting\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

trait ResolvesJobPostingUser
{
    protected function authenticatedUser(): ?User
    {
        $token = $this->bearerToken();

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            $tokenable = $accessToken?->tokenable;

            if ($tokenable instanceof User) {
                return $tokenable->withAccessToken($accessToken);
            }
        }

        $resolved = $this->user('sanctum') ?? $this->user();

        return $resolved instanceof User ? $resolved : null;
    }

    protected function isEmployerUser(): bool
    {
        return in_array(
            $this->authenticatedUser()?->role,
            [UserRole::EMPLOYER, UserRole::ADMIN],
            true,
        );
    }
}

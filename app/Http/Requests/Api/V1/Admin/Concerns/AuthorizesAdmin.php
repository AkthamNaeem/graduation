<?php

namespace App\Http\Requests\Api\V1\Admin\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

trait AuthorizesAdmin
{
    public function authorize(): bool
    {
        return $this->authenticatedUser()?->role === UserRole::ADMIN;
    }

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
}

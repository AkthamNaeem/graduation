<?php

namespace App\Http\Resources\Api\V1\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

trait ResolvesResourceViewer
{
    protected function viewerRole(Request $request): ?UserRole
    {
        $cachedRole = $request->attributes->get('_resource_viewer_role');
        if (is_string($cachedRole)) {
            return UserRole::tryFrom($cachedRole);
        }

        $tokenable = $request->bearerToken()
            ? PersonalAccessToken::findToken($request->bearerToken())?->tokenable
            : null;
        $role = $tokenable instanceof User ? $tokenable->role : $request->user('sanctum')?->role;

        if ($role instanceof UserRole) {
            $request->attributes->set('_resource_viewer_role', $role->value);

            return $role;
        }

        if (is_string($role)) {
            $request->attributes->set('_resource_viewer_role', $role);

            return UserRole::tryFrom($role);
        }

        return null;
    }

    protected function viewerIsManager(Request $request): bool
    {
        return in_array($this->viewerRole($request), [UserRole::EMPLOYER, UserRole::ADMIN], true);
    }
}

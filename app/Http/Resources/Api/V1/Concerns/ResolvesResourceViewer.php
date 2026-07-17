<?php

namespace App\Http\Resources\Api\V1\Concerns;

use App\Enums\UserRole;
use Illuminate\Http\Request;

trait ResolvesResourceViewer
{
    protected function viewerRole(Request $request): ?UserRole
    {
        $role = $request->user('sanctum')?->role;

        if ($role instanceof UserRole) {
            return $role;
        }

        return is_string($role) ? UserRole::tryFrom($role) : null;
    }

    protected function viewerIsManager(Request $request): bool
    {
        return in_array($this->viewerRole($request), [UserRole::EMPLOYER, UserRole::ADMIN], true);
    }
}

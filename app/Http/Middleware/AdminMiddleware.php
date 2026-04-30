<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authenticatedUser($request)?->role !== UserRole::ADMIN) {
            return ApiResponse::error(
                message: 'This action is unauthorized.',
                status: 403,
            );
        }

        return $next($request);
    }

    private function authenticatedUser(Request $request): ?User
    {
        $token = $request->bearerToken();

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

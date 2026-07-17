<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if ($user?->status !== UserStatus::ACTIVE) {
            return ApiResponse::error(
                message: 'Your account is suspended.',
                status: 403,
                code: 'USER_SUSPENDED',
            );
        }

        return $next($request);
    }
}

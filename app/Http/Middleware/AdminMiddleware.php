<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (($request->user('sanctum') ?? $request->user())?->role !== UserRole::ADMIN) {
            return ApiResponse::error(
                message: __('api.unauthorized'),
                status: 403,
            );
        }

        return $next($request);
    }
}

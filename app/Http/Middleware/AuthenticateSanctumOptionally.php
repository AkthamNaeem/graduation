<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSanctumOptionally
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if ($authorization === null) {
            return $next($request);
        }

        if ($request->bearerToken() === null) {
            return ApiResponse::error(
                message: __('api.unauthenticated'),
                status: 401,
                code: 'INVALID_AUTHORIZATION_TOKEN',
            );
        }

        $user = Auth::guard('sanctum')->user();

        if ($user === null) {
            return ApiResponse::error(
                message: __('api.unauthenticated'),
                status: 401,
                code: 'INVALID_AUTHORIZATION_TOKEN',
            );
        }

        return app(EnsureUserIsActive::class)->handle($request, $next);
    }
}

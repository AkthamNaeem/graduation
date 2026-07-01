<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyApproved
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if (! $user || $user->role !== UserRole::EMPLOYER) {
            return $next($request);
        }

        $company = $user->employerProfile()->with('company')->first()?->company;

        if ($company?->approval_status === 'approved') {
            return $next($request);
        }

        $status = $company?->approval_status ?? 'missing';

        return ApiResponse::error(
            message: 'Your company must be approved before using employer workflows.',
            errors: [
                'company_approval_status' => [$status],
            ],
            status: 403,
        );
    }
}

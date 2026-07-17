<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\CompanyRecruitmentAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyApproved
{
    public function __construct(
        private readonly CompanyRecruitmentAccessService $companyAccessService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if (! $user || $user->role !== UserRole::EMPLOYER) {
            return $next($request);
        }

        $this->companyAccessService->assertEmployerCanRecruit($user);

        return $next($request);
    }
}

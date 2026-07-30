<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Test;
use App\Models\TestAttempt;
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

        if (! $user) {
            return $next($request);
        }

        if ($user->role === UserRole::EMPLOYER) {
            $this->companyAccessService->assertEmployerCanRecruit($user);
        } elseif ($user->role === UserRole::ADMIN) {
            $company = $this->resolveAdminTargetCompany($request);
            if ($company instanceof Company) {
                $this->companyAccessService->assertCompanyOperational($company);
            }
        }

        return $next($request);
    }

    private function resolveAdminTargetCompany(Request $request): ?Company
    {
        if ($request->filled('company_id')) {
            return Company::query()->findOrFail((int) $request->input('company_id'));
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Company) {
                return $parameter;
            }
            if ($parameter instanceof JobPosting
                || $parameter instanceof JobApplication
                || $parameter instanceof ApplicationTestAssignment
                || $parameter instanceof TestAttempt
                || $parameter instanceof Interview
                || $parameter instanceof Test) {
                return $this->companyAccessService->companyFor($parameter);
            }
        }

        return null;
    }
}

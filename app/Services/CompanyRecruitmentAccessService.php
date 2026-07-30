<?php

namespace App\Services;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use App\Exceptions\RecruitmentAccessException;
use App\Models\ApplicationTestAssignment;
use App\Models\Company;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Models\User;

class CompanyRecruitmentAccessService
{
    public function __construct(
        private readonly CompanyPermissionService $permissionService,
    ) {}

    public function companyForEmployer(User $user): ?Company
    {
        return $this->permissionService->activeMembership($user)?->company;
    }

    public function companyFor(JobPosting|JobApplication|ApplicationTestAssignment|TestAttempt|Interview|Test $subject): ?Company
    {
        return match (true) {
            $subject instanceof JobPosting => $subject->company()->first(),
            $subject instanceof JobApplication => $subject->jobPosting()->with('company')->first()?->company,
            $subject instanceof ApplicationTestAssignment => $subject->jobApplication()
                ->with('jobPosting.company')->first()?->jobPosting?->company,
            $subject instanceof TestAttempt => $subject->applicationTestAssignment()
                ->with('jobApplication.jobPosting.company')->first()?->jobApplication?->jobPosting?->company,
            $subject instanceof Interview => $subject->jobApplication()
                ->with('jobPosting.company')->first()?->jobPosting?->company,
            $subject instanceof Test => $subject->company()->first(),
        };
    }

    public function assertEmployerCanRecruit(User $user): Company
    {
        $company = $this->companyForEmployer($user);

        if (! $company instanceof Company) {
            throw new RecruitmentAccessException(__('domain_errors.COMPANY_PROFILE_MISSING'), 'COMPANY_PROFILE_MISSING',
                errors: ['company_approval_status' => ['missing']],
            );
        }

        $this->assertApproved($company);
        $this->assertHasOwner($company);

        return $company;
    }

    public function assertRecruitmentAvailable(JobPosting|JobApplication|ApplicationTestAssignment|TestAttempt|Interview|Test $subject): Company
    {
        $company = $this->companyFor($subject);

        if (! $company instanceof Company || $company->approval_status !== CompanyApprovalStatus::APPROVED->value) {
            throw new RecruitmentAccessException(__('domain_errors.COMPANY_RECRUITMENT_UNAVAILABLE'), 'COMPANY_RECRUITMENT_UNAVAILABLE',
            );
        }

        $this->assertHasOwner($company);

        return $company;
    }

    public function assertCompanyOperational(Company $company): void
    {
        $this->assertApproved($company);
        $this->assertHasOwner($company);
    }

    public function isApproved(?Company $company): bool
    {
        return $company?->approval_status === CompanyApprovalStatus::APPROVED->value;
    }

    private function assertApproved(Company $company): void
    {
        [$message, $code] = match ($company->approval_status) {
            CompanyApprovalStatus::PENDING->value => ['Your company is pending approval.', 'COMPANY_PENDING'],
            CompanyApprovalStatus::REJECTED->value => ['Your company application was rejected.', 'COMPANY_REJECTED'],
            CompanyApprovalStatus::SUSPENDED->value => ['Your company has been suspended.', 'COMPANY_SUSPENDED'],
            default => [__('companies.not_approved'), 'COMPANY_RECRUITMENT_UNAVAILABLE'],
        };

        if ($company->approval_status !== CompanyApprovalStatus::APPROVED->value) {
            throw new RecruitmentAccessException(
                $message,
                $code,
                errors: ['company_approval_status' => [$company->approval_status]],
            );
        }
    }

    private function assertHasOwner(Company $company): void
    {
        if (! $company->owner_setup_required) {
            return;
        }

        $hasOwner = $company->employerProfiles()
            ->where('company_role', CompanyRole::OWNER)
            ->where('membership_status', CompanyMembershipStatus::ACTIVE)
            ->exists();

        if (! $hasOwner) {
            throw new RecruitmentAccessException(__('domain_errors.COMPANY_SETUP_OWNER_REQUIRED'), 'COMPANY_SETUP_OWNER_REQUIRED',
                409,
            );
        }
    }
}

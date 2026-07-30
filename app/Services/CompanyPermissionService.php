<?php

namespace App\Services;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyPermission;
use App\Enums\UserRole;
use App\Exceptions\CompanyManagementException;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\User;

class CompanyPermissionService
{
    public function isAdministrator(User $user): bool
    {
        return $user->role === UserRole::ADMIN;
    }

    public function activeMembership(User $user): ?EmployerProfile
    {
        if ($user->role !== UserRole::EMPLOYER) {
            return null;
        }

        $profile = $user->relationLoaded('employerProfile')
            ? $user->employerProfile
            : $user->employerProfile()->with('company')->first();

        return $profile?->membership_status === CompanyMembershipStatus::ACTIVE
            ? $profile
            : null;
    }

    public function can(
        User $user,
        CompanyPermission $permission,
        Company|int|null $company = null,
    ): bool {
        if ($this->isAdministrator($user)) {
            return true;
        }

        $membership = $this->activeMembership($user);
        if (! $membership instanceof EmployerProfile) {
            return false;
        }

        $companyId = $company instanceof Company ? $company->id : $company;
        if ($companyId !== null && (int) $membership->company_id !== (int) $companyId) {
            return false;
        }

        return $membership->company_role->allows($permission);
    }

    public function companyFor(User $user): Company
    {
        $membership = $this->activeMembership($user);

        if (! $membership instanceof EmployerProfile) {
            throw new CompanyManagementException(
                'An active company membership is required.',
                'COMPANY_MEMBER_INACTIVE',
                403,
            );
        }

        return $membership->company()->firstOrFail();
    }

    public function assertCan(
        User $user,
        CompanyPermission $permission,
        Company|int|null $company = null,
    ): void {
        if (! $this->can($user, $permission, $company)) {
            throw new CompanyManagementException(
                'Your company role does not allow this action.',
                'COMPANY_MEMBER_ROLE_FORBIDDEN',
                403,
            );
        }
    }
}

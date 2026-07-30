<?php

namespace App\Enums;

enum CompanyRole: string
{
    case OWNER = 'owner';
    case COMPANY_ADMIN = 'company_admin';
    case RECRUITER = 'recruiter';
    case INTERVIEWER = 'interviewer';
    case REVIEWER = 'reviewer';

    public function allows(CompanyPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function canManageMembers(): bool
    {
        return $this === self::OWNER || $this === self::COMPANY_ADMIN;
    }

    public function canAssign(self $role): bool
    {
        return match ($this) {
            self::OWNER => true,
            self::COMPANY_ADMIN => $role !== self::OWNER,
            default => false,
        };
    }

    /**
     * @return list<CompanyPermission>
     */
    private function permissions(): array
    {
        return match ($this) {
            self::OWNER => CompanyPermission::cases(),
            self::COMPANY_ADMIN => [
                CompanyPermission::UPDATE_COMPANY,
                CompanyPermission::VIEW_TEAM,
                CompanyPermission::MANAGE_TEAM,
                CompanyPermission::VIEW_JOBS,
                CompanyPermission::MANAGE_JOBS,
                CompanyPermission::VIEW_APPLICATIONS,
                CompanyPermission::MANAGE_APPLICATIONS,
                CompanyPermission::VIEW_TESTS,
                CompanyPermission::MANAGE_TESTS,
                CompanyPermission::GRADE_TESTS,
                CompanyPermission::VIEW_INTERVIEWS,
                CompanyPermission::MANAGE_INTERVIEWS,
                CompanyPermission::EVALUATE_INTERVIEWS,
                CompanyPermission::MANAGE_INTERNAL_NOTES,
            ],
            self::RECRUITER => [
                CompanyPermission::VIEW_JOBS,
                CompanyPermission::MANAGE_JOBS,
                CompanyPermission::VIEW_APPLICATIONS,
                CompanyPermission::MANAGE_APPLICATIONS,
                CompanyPermission::VIEW_TESTS,
                CompanyPermission::MANAGE_TESTS,
                CompanyPermission::VIEW_INTERVIEWS,
                CompanyPermission::MANAGE_INTERVIEWS,
                CompanyPermission::MANAGE_INTERNAL_NOTES,
            ],
            self::INTERVIEWER => [
                CompanyPermission::VIEW_APPLICATIONS,
                CompanyPermission::VIEW_INTERVIEWS,
                CompanyPermission::EVALUATE_INTERVIEWS,
            ],
            self::REVIEWER => [
                CompanyPermission::VIEW_APPLICATIONS,
                CompanyPermission::VIEW_TESTS,
                CompanyPermission::GRADE_TESTS,
                CompanyPermission::MANAGE_INTERNAL_NOTES,
            ],
        };
    }
}

<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\User;
use App\Services\CompanyPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyRoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_roles_follow_the_operational_permissions_matrix(): void
    {
        $company = Company::query()->create(['name' => 'Permissions']);
        $permissions = app(CompanyPermissionService::class);
        $owner = $this->member($company, CompanyRole::OWNER);
        $companyAdmin = $this->member($company, CompanyRole::COMPANY_ADMIN);
        $recruiter = $this->member($company, CompanyRole::RECRUITER);
        $interviewer = $this->member($company, CompanyRole::INTERVIEWER);
        $reviewer = $this->member($company, CompanyRole::REVIEWER);

        foreach ([$owner, $companyAdmin] as $manager) {
            $this->assertTrue($permissions->can($manager, CompanyPermission::MANAGE_TEAM, $company));
            $this->assertTrue($permissions->can($manager, CompanyPermission::MANAGE_JOBS, $company));
            $this->assertTrue($permissions->can($manager, CompanyPermission::MANAGE_APPLICATIONS, $company));
        }
        $this->assertTrue($permissions->can($owner, CompanyPermission::TRANSFER_OWNERSHIP, $company));
        $this->assertFalse($permissions->can($companyAdmin, CompanyPermission::TRANSFER_OWNERSHIP, $company));

        $this->assertTrue($permissions->can($recruiter, CompanyPermission::MANAGE_JOBS, $company));
        $this->assertTrue($permissions->can($recruiter, CompanyPermission::MANAGE_APPLICATIONS, $company));
        $this->assertFalse($permissions->can($recruiter, CompanyPermission::MANAGE_TEAM, $company));

        $this->assertTrue($permissions->can($interviewer, CompanyPermission::EVALUATE_INTERVIEWS, $company));
        $this->assertFalse($permissions->can($interviewer, CompanyPermission::MANAGE_JOBS, $company));
        $this->assertTrue($permissions->can($reviewer, CompanyPermission::GRADE_TESTS, $company));
        $this->assertTrue($permissions->can($reviewer, CompanyPermission::MANAGE_INTERNAL_NOTES, $company));
        $this->assertFalse($permissions->can($reviewer, CompanyPermission::MANAGE_INTERVIEWS, $company));
    }

    public function test_admin_bypasses_roles_while_inactive_and_cross_company_members_do_not(): void
    {
        $company = Company::query()->create(['name' => 'One']);
        $otherCompany = Company::query()->create(['name' => 'Two']);
        $permissions = app(CompanyPermissionService::class);
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
            'status' => UserStatus::ACTIVE,
        ]);
        $member = $this->member($company, CompanyRole::OWNER);

        $this->assertTrue($permissions->can($admin, CompanyPermission::MANAGE_TEAM, $otherCompany));
        $this->assertFalse($permissions->can($member, CompanyPermission::MANAGE_TEAM, $otherCompany));

        $member->employerProfile->forceFill([
            'membership_status' => CompanyMembershipStatus::SUSPENDED,
        ])->save();
        $member->refresh()->load('employerProfile');
        $this->assertFalse($permissions->can($member, CompanyPermission::MANAGE_TEAM, $company));
    }

    private function member(Company $company, CompanyRole $role): User
    {
        $user = User::factory()->create([
            'role' => UserRole::EMPLOYER,
            'status' => UserStatus::ACTIVE,
        ]);
        EmployerProfile::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'company_role' => $role,
            'membership_status' => CompanyMembershipStatus::ACTIVE,
        ]);

        return $user->refresh()->load('employerProfile');
    }
}

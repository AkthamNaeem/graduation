<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyInvitationStatus;
use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\EmployerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_company_with_owner_invitation_without_storing_raw_token(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/companies', [
            'name' => 'Invited Company',
            'approval_status' => CompanyApprovalStatus::APPROVED->value,
            'owner' => ['name' => 'Future Owner', 'email' => 'OWNER@EXAMPLE.COM '],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.company.has_owner', false)
            ->assertJsonPath('data.company.setup_complete', false)
            ->assertJsonPath('data.owner_invitation.email', 'owner@example.com')
            ->assertJsonStructure(['data' => ['owner_invitation' => ['token']]]);

        $token = $response->json('data.owner_invitation.token');
        $invitation = CompanyInvitation::query()->sole();
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertArrayNotHasKey('token_hash', $response->json('data.owner_invitation'));
    }

    public function test_public_employer_registration_is_disabled(): void
    {
        $this->postJson('/api/v1/auth/register/employer', [])
            ->assertForbidden()
            ->assertJsonPath('code', 'EMPLOYER_SELF_REGISTRATION_DISABLED');
    }

    public function test_admin_company_cannot_recruit_until_owner_invitation_is_accepted(): void
    {
        $admin = $this->user(UserRole::ADMIN);
        Sanctum::actingAs($admin);
        $companyId = $this->postJson('/api/v1/admin/companies', [
            'name' => 'Setup Pending',
            'approval_status' => CompanyApprovalStatus::APPROVED->value,
        ])->assertCreated()->json('data.company.id');

        $this->postJson('/api/v1/tests', [
            'company_id' => $companyId,
            'title' => 'Blocked Test',
            'duration_minutes' => 30,
        ])->assertConflict()
            ->assertJsonPath('code', 'COMPANY_SETUP_OWNER_REQUIRED');

        $token = $this->postJson("/api/v1/admin/companies/{$companyId}/invitations", [
            'email' => 'setup.owner@example.com',
            'company_role' => CompanyRole::OWNER->value,
        ])->assertCreated()->json('data.token');
        $this->postJson("/api/v1/company-invitations/{$token}/accept", [
            'name' => 'Setup Owner',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])->assertCreated();
        $this->assertFalse(Company::query()->findOrFail($companyId)->owner_setup_required);
        $this->assertDatabaseHas('employer_profiles', [
            'company_id' => $companyId,
            'company_role' => CompanyRole::OWNER->value,
            'membership_status' => CompanyMembershipStatus::ACTIVE->value,
        ]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/tests', [
            'company_id' => $companyId,
            'title' => 'Enabled Test',
            'duration_minutes' => 30,
        ])->assertCreated();
    }

    public function test_new_user_accepts_invitation_into_existing_company_and_cannot_reuse_it(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        Sanctum::actingAs($owner);
        $token = $this->postJson('/api/v1/company/invitations', [
            'email' => 'new.recruiter@example.com',
            'company_role' => CompanyRole::RECRUITER->value,
        ])->assertCreated()->json('data.token');

        $companyCount = Company::query()->count();
        $this->postJson("/api/v1/company-invitations/{$token}/accept", [
            'name' => 'New Recruiter',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])->assertCreated()
            ->assertJsonPath('data.company_role.key', CompanyRole::RECRUITER->value)
            ->assertJsonPath('data.membership_status.key', CompanyMembershipStatus::ACTIVE->value);

        $this->assertSame($companyCount, Company::query()->count());
        $this->assertDatabaseHas('employer_profiles', [
            'company_id' => $company->id,
            'company_role' => CompanyRole::RECRUITER->value,
            'membership_status' => CompanyMembershipStatus::ACTIVE->value,
        ]);
        $this->assertNotNull(
            User::query()->where('email', 'new.recruiter@example.com')->sole()->email_verified_at,
        );

        $this->postJson("/api/v1/company-invitations/{$token}/accept", [])
            ->assertConflict()
            ->assertJsonPath('code', 'COMPANY_INVITATION_ALREADY_USED');
    }

    public function test_duplicate_pending_invitation_and_cross_company_membership_are_rejected(): void
    {
        [$owner] = $this->ownerAndCompany();
        Sanctum::actingAs($owner);
        $payload = [
            'email' => 'duplicate@example.com',
            'company_role' => CompanyRole::RECRUITER->value,
        ];
        $this->postJson('/api/v1/company/invitations', $payload)->assertCreated();
        $this->postJson('/api/v1/company/invitations', $payload)
            ->assertConflict()
            ->assertJsonPath('code', 'COMPANY_INVITATION_DUPLICATE_PENDING');

        [$otherOwner] = $this->ownerAndCompany('Other Company');
        $this->postJson('/api/v1/company/invitations', [
            'email' => $otherOwner->email,
            'company_role' => CompanyRole::RECRUITER->value,
        ])->assertConflict()
            ->assertJsonPath('code', 'COMPANY_MEMBER_DIFFERENT_COMPANY');
    }

    public function test_existing_employer_without_membership_accepts_but_other_global_roles_do_not(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $employer = $this->user(UserRole::EMPLOYER);
        $jobSeeker = $this->user(UserRole::JOB_SEEKER);
        Sanctum::actingAs($owner);

        $employerToken = $this->postJson('/api/v1/company/invitations', [
            'email' => $employer->email,
            'company_role' => CompanyRole::INTERVIEWER->value,
        ])->assertCreated()->json('data.token');
        $this->postJson("/api/v1/company-invitations/{$employerToken}/accept")
            ->assertCreated()
            ->assertJsonPath('data.user_id', $employer->id);
        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $employer->id,
            'company_id' => $company->id,
        ]);

        $this->postJson('/api/v1/company/invitations', [
            'email' => $jobSeeker->email,
            'company_role' => CompanyRole::REVIEWER->value,
        ])->assertConflict()
            ->assertJsonPath('code', 'COMPANY_INVITATION_USER_ROLE_CONFLICT');
        $this->assertDatabaseMissing('employer_profiles', ['user_id' => $jobSeeker->id]);
    }

    public function test_removed_member_is_reactivated_only_by_same_company_invitation(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $removed = $this->member($company, CompanyRole::RECRUITER);
        $removed->employerProfile->forceFill([
            'membership_status' => CompanyMembershipStatus::REMOVED,
            'removed_at' => now()->subDay(),
        ])->save();
        Sanctum::actingAs($owner);

        $token = $this->postJson('/api/v1/company/invitations', [
            'email' => $removed->email,
            'company_role' => CompanyRole::REVIEWER->value,
        ])->assertCreated()->json('data.token');
        $this->postJson("/api/v1/company-invitations/{$token}/accept")
            ->assertCreated()
            ->assertJsonPath('data.user_id', $removed->id)
            ->assertJsonPath('data.company_role.key', CompanyRole::REVIEWER->value)
            ->assertJsonPath('data.membership_status.key', CompanyMembershipStatus::ACTIVE->value);

        $this->assertDatabaseCount('employer_profiles', 2);
        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $removed->id,
            'company_id' => $company->id,
            'removed_at' => null,
        ]);
    }

    public function test_resend_rotates_token_and_invalidates_the_previous_token(): void
    {
        [$owner] = $this->ownerAndCompany();
        Sanctum::actingAs($owner);
        $created = $this->postJson('/api/v1/company/invitations', [
            'email' => 'rotated@example.com',
            'company_role' => CompanyRole::RECRUITER->value,
        ])->assertCreated();
        $oldToken = $created->json('data.token');
        $invitationId = $created->json('data.invitation.id');

        $newToken = $this->postJson("/api/v1/company/invitations/{$invitationId}/resend")
            ->assertOk()
            ->json('data.token');
        $this->assertNotSame($oldToken, $newToken);
        $this->getJson("/api/v1/company-invitations/{$oldToken}")
            ->assertNotFound()
            ->assertJsonPath('code', 'COMPANY_INVITATION_NOT_FOUND');
        $this->getJson("/api/v1/company-invitations/{$newToken}")
            ->assertOk()
            ->assertJsonPath('data.email', 'rotated@example.com');
    }

    public function test_company_admin_cannot_assign_owner_and_recruiter_cannot_manage_team(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $companyAdmin = $this->member($company, CompanyRole::COMPANY_ADMIN);
        $recruiter = $this->member($company, CompanyRole::RECRUITER);

        Sanctum::actingAs($companyAdmin);
        $this->postJson('/api/v1/company/invitations', [
            'email' => 'forbidden-owner@example.com',
            'company_role' => CompanyRole::OWNER->value,
        ])->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_INVITATION_ROLE_FORBIDDEN');

        Sanctum::actingAs($recruiter);
        $this->getJson('/api/v1/company/members')
            ->assertForbidden()
            ->assertJsonPath('code', 'COMPANY_MEMBER_ROLE_FORBIDDEN');
    }

    public function test_suspending_member_revokes_tokens_and_reactivation_restores_membership(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $member = $this->member($company, CompanyRole::RECRUITER);
        $member->createToken('member-session');
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/company/members/{$member->id}/status", [
            'membership_status' => CompanyMembershipStatus::SUSPENDED->value,
        ])->assertOk()
            ->assertJsonPath('data.membership_status.key', CompanyMembershipStatus::SUSPENDED->value);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->patchJson("/api/v1/company/members/{$member->id}/status", [
            'membership_status' => CompanyMembershipStatus::ACTIVE->value,
        ])->assertOk()
            ->assertJsonPath('data.membership_status.key', CompanyMembershipStatus::ACTIVE->value);
    }

    public function test_last_owner_cannot_be_removed_or_demoted_even_by_admin(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $admin = $this->user(UserRole::ADMIN);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/admin/companies/{$company->id}/members/{$owner->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'COMPANY_LAST_OWNER_REQUIRED');
        $this->patchJson("/api/v1/admin/companies/{$company->id}/members/{$owner->id}/role", [
            'company_role' => CompanyRole::COMPANY_ADMIN->value,
        ])->assertConflict()
            ->assertJsonPath('code', 'COMPANY_LAST_OWNER_REQUIRED');
    }

    public function test_owner_transfers_ownership_atomically(): void
    {
        [$owner, $company] = $this->ownerAndCompany();
        $target = $this->member($company, CompanyRole::RECRUITER);
        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/company/transfer-ownership', [
            'new_owner_user_id' => $target->id,
        ])->assertOk()
            ->assertJsonPath('data.user_id', $target->id)
            ->assertJsonPath('data.company_role.key', CompanyRole::OWNER->value);

        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $owner->id,
            'company_role' => CompanyRole::COMPANY_ADMIN->value,
        ]);
        $this->assertDatabaseHas('employer_profiles', [
            'user_id' => $target->id,
            'company_role' => CompanyRole::OWNER->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'company.ownership.transferred',
            'entity_id' => $company->id,
        ]);
    }

    public function test_revoked_and_expired_invitations_cannot_be_accepted(): void
    {
        [$owner] = $this->ownerAndCompany();
        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/v1/company/invitations', [
            'email' => 'revoked@example.com',
            'company_role' => CompanyRole::REVIEWER->value,
        ])->assertCreated();
        $token = $response->json('data.token');
        $invitationId = $response->json('data.invitation.id');

        $this->postJson("/api/v1/company/invitations/{$invitationId}/revoke")
            ->assertOk();
        $this->postJson("/api/v1/company-invitations/{$token}/accept", [
            'name' => 'Revoked',
            'password' => 'StrongPassword123!',
            'password_confirmation' => 'StrongPassword123!',
        ])->assertConflict()
            ->assertJsonPath('code', 'COMPANY_INVITATION_REVOKED');

        $expiredToken = $this->postJson('/api/v1/company/invitations', [
            'email' => 'expired@example.com',
            'company_role' => CompanyRole::REVIEWER->value,
        ])->assertCreated()->json('data.token');
        CompanyInvitation::query()
            ->where('email', 'expired@example.com')
            ->update(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/v1/company-invitations/{$expiredToken}")
            ->assertConflict()
            ->assertJsonPath('code', 'COMPANY_INVITATION_EXPIRED');
        $this->assertDatabaseHas('company_invitations', [
            'email' => 'expired@example.com',
            'status' => CompanyInvitationStatus::EXPIRED->value,
        ]);
    }

    /**
     * @return array{User, Company}
     */
    private function ownerAndCompany(string $name = 'Example Company'): array
    {
        $company = Company::query()->create([
            'name' => $name,
            'approval_status' => CompanyApprovalStatus::APPROVED,
        ]);
        $owner = $this->user(UserRole::EMPLOYER);
        EmployerProfile::query()->create([
            'user_id' => $owner->id,
            'company_id' => $company->id,
            'company_role' => CompanyRole::OWNER,
            'membership_status' => CompanyMembershipStatus::ACTIVE,
            'joined_at' => now(),
        ]);
        $owner->refresh()->load('employerProfile');

        return [$owner, $company];
    }

    private function member(Company $company, CompanyRole $role): User
    {
        $user = $this->user(UserRole::EMPLOYER);
        EmployerProfile::query()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'company_role' => $role,
            'membership_status' => CompanyMembershipStatus::ACTIVE,
            'joined_at' => now(),
        ]);

        return $user->refresh()->load('employerProfile');
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => UserStatus::ACTIVE,
        ]);
    }
}

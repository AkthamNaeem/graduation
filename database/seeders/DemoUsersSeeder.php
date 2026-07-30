<?php

namespace Database\Seeders;

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
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    /** @var array<string, array{name:string,role:UserRole,status:UserStatus}> */
    public const ACCOUNTS = [
        'admin@workey.test' => ['name' => 'Platform Administrator', 'role' => UserRole::ADMIN, 'status' => UserStatus::ACTIVE],
        'admin.suspended@workey.test' => ['name' => 'Suspended Administrator', 'role' => UserRole::ADMIN, 'status' => UserStatus::SUSPENDED],
        'employer.approved@workey.test' => ['name' => 'Maya Hiring Manager', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.recruiter@workey.test' => ['name' => 'Omar Technical Recruiter', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.company-admin@workey.test' => ['name' => 'Salma Company Administrator', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.interviewer@workey.test' => ['name' => 'Yazan Interviewer', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.reviewer@workey.test' => ['name' => 'Rana Test Reviewer', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.membership-suspended@workey.test' => ['name' => 'Suspended Team Member', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.membership-removed@workey.test' => ['name' => 'Removed Team Member', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.pending@workey.test' => ['name' => 'Pending Employer', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.rejected@workey.test' => ['name' => 'Rejected Employer', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'employer.suspended@workey.test' => ['name' => 'Suspended Company Employer', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::SUSPENDED],
        'employer.second@workey.test' => ['name' => 'Second Company Employer', 'role' => UserRole::EMPLOYER, 'status' => UserStatus::ACTIVE],
        'seeker.backend@workey.test' => ['name' => 'Lina Backend', 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE],
        'seeker.frontend@workey.test' => ['name' => 'Karim Frontend', 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE],
        'seeker.data@workey.test' => ['name' => 'Nour Data', 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE],
        'seeker.junior@workey.test' => ['name' => 'Tala Junior', 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE],
        'seeker.senior@workey.test' => ['name' => 'Samer Senior', 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE],
        'seeker.incomplete@workey.test' => ['name' => 'Rami Incomplete', 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::ACTIVE],
        'seeker.suspended@workey.test' => ['name' => 'Dalia Suspended', 'role' => UserRole::JOB_SEEKER, 'status' => UserStatus::SUSPENDED],
    ];

    /** @var array<string, array{name:string,status:CompanyApprovalStatus,industry:string}> */
    public const COMPANIES = [
        'Workey Labs' => ['name' => 'Workey Labs', 'status' => CompanyApprovalStatus::APPROVED, 'industry' => 'Recruitment Technology'],
        'Pending Ventures' => ['name' => 'Pending Ventures', 'status' => CompanyApprovalStatus::PENDING, 'industry' => 'Fintech'],
        'Rejected Systems' => ['name' => 'Rejected Systems', 'status' => CompanyApprovalStatus::REJECTED, 'industry' => 'Software'],
        'Suspended Digital' => ['name' => 'Suspended Digital', 'status' => CompanyApprovalStatus::SUSPENDED, 'industry' => 'Digital Services'],
        'Damascus Data Co.' => ['name' => 'Damascus Data Co.', 'status' => CompanyApprovalStatus::APPROVED, 'industry' => 'Data and AI'],
    ];

    public function run(): void
    {
        $now = DemoSeederContext::now();
        $password = Hash::make('password');

        foreach (self::ACCOUNTS as $email => $account) {
            User::query()->create([
                ...$account,
                'email' => $email,
                'email_verified_at' => $account['status'] === UserStatus::ACTIVE ? $now : null,
                'password' => $password,
                'created_at' => $now->copy()->subDays(60),
                'updated_at' => $now->copy()->subDays(2),
            ]);
        }

        foreach (self::COMPANIES as $company) {
            Company::query()->create([
                'name' => $company['name'],
                'industry' => $company['industry'],
                'website' => 'https://'.str($company['name'])->slug().'.example.test',
                'location' => 'Damascus, Syria',
                'description' => "Demo {$company['industry']} company covering the {$company['status']->value} approval state.",
                'approval_status' => $company['status']->value,
                'created_at' => $now->copy()->subDays(55),
                'updated_at' => $now->copy()->subDays(3),
            ]);
        }

        $links = [
            'employer.approved@workey.test' => ['Workey Labs', 'Head of Talent', CompanyRole::OWNER, CompanyMembershipStatus::ACTIVE],
            'employer.company-admin@workey.test' => ['Workey Labs', 'Company Administrator', CompanyRole::COMPANY_ADMIN, CompanyMembershipStatus::ACTIVE],
            'employer.recruiter@workey.test' => ['Workey Labs', 'Technical Recruiter', CompanyRole::RECRUITER, CompanyMembershipStatus::ACTIVE],
            'employer.interviewer@workey.test' => ['Workey Labs', 'Technical Interviewer', CompanyRole::INTERVIEWER, CompanyMembershipStatus::ACTIVE],
            'employer.reviewer@workey.test' => ['Workey Labs', 'Assessment Reviewer', CompanyRole::REVIEWER, CompanyMembershipStatus::ACTIVE],
            'employer.membership-suspended@workey.test' => ['Workey Labs', 'Sourcer', CompanyRole::RECRUITER, CompanyMembershipStatus::SUSPENDED],
            'employer.membership-removed@workey.test' => ['Workey Labs', 'Former Recruiter', CompanyRole::RECRUITER, CompanyMembershipStatus::REMOVED],
            'employer.pending@workey.test' => ['Pending Ventures', 'Founder', CompanyRole::OWNER, CompanyMembershipStatus::ACTIVE],
            'employer.rejected@workey.test' => ['Rejected Systems', 'HR Manager', CompanyRole::OWNER, CompanyMembershipStatus::ACTIVE],
            'employer.suspended@workey.test' => ['Suspended Digital', 'Recruitment Lead', CompanyRole::OWNER, CompanyMembershipStatus::ACTIVE],
            'employer.second@workey.test' => ['Damascus Data Co.', 'People Operations Manager', CompanyRole::OWNER, CompanyMembershipStatus::ACTIVE],
        ];

        foreach ($links as $index => $link) {
            [$email, $companyName, $jobTitle, $companyRole, $membershipStatus] = [
                $index,
                $link[0],
                $link[1],
                $link[2],
                $link[3],
            ];
            EmployerProfile::query()->create([
                'user_id' => User::query()->where('email', $email)->valueOrFail('id'),
                'company_id' => Company::query()->where('name', $companyName)->valueOrFail('id'),
                'job_title' => $jobTitle,
                'company_role' => $companyRole,
                'membership_status' => $membershipStatus,
                'joined_at' => $now->copy()->subDays(50),
                'suspended_at' => $membershipStatus === CompanyMembershipStatus::SUSPENDED
                    ? $now->copy()->subDays(5)
                    : null,
                'removed_at' => $membershipStatus === CompanyMembershipStatus::REMOVED
                    ? $now->copy()->subDays(5)
                    : null,
                'phone' => '+963 11 '.match ($email) {
                    'employer.approved@workey.test' => '1000001',
                    'employer.recruiter@workey.test' => '1000002',
                    'employer.pending@workey.test' => '1000003',
                    'employer.rejected@workey.test' => '1000004',
                    'employer.suspended@workey.test' => '1000005',
                    default => '1000006',
                },
                'bio' => "Demo employer profile for {$jobTitle}.",
                'created_at' => $now->copy()->subDays(50),
                'updated_at' => $now->copy()->subDays(2),
            ]);
        }

        $workeyLabsId = Company::query()->where('name', 'Workey Labs')->valueOrFail('id');
        $adminId = User::query()->where('email', 'admin@workey.test')->valueOrFail('id');
        $acceptedUserId = User::query()->where('email', 'employer.recruiter@workey.test')->valueOrFail('id');
        $invitations = [
            ['pending.invitation@workey.test', CompanyRole::RECRUITER, CompanyInvitationStatus::PENDING, $now->copy()->addDays(3)],
            ['expired.invitation@workey.test', CompanyRole::INTERVIEWER, CompanyInvitationStatus::EXPIRED, $now->copy()->subDay()],
            ['revoked.invitation@workey.test', CompanyRole::REVIEWER, CompanyInvitationStatus::REVOKED, $now->copy()->addDay()],
            ['employer.recruiter@workey.test', CompanyRole::RECRUITER, CompanyInvitationStatus::ACCEPTED, $now->copy()->addDay()],
        ];
        foreach ($invitations as $offset => [$email, $role, $status, $expiresAt]) {
            CompanyInvitation::query()->create([
                'company_id' => $workeyLabsId,
                'email' => $email,
                'company_role' => $role,
                'token_hash' => hash('sha256', "demo-company-invitation-{$offset}"),
                'status' => $status,
                'invited_by_user_id' => $adminId,
                'expires_at' => $expiresAt,
                'accepted_at' => $status === CompanyInvitationStatus::ACCEPTED ? $now->copy()->subDays(2) : null,
                'accepted_by_user_id' => $status === CompanyInvitationStatus::ACCEPTED ? $acceptedUserId : null,
                'revoked_at' => $status === CompanyInvitationStatus::REVOKED ? $now->copy()->subDays(2) : null,
            ]);
        }
    }
}

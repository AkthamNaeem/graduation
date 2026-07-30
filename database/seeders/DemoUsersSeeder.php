<?php

namespace Database\Seeders;

use App\Enums\CompanyApprovalStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
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
            'employer.approved@workey.test' => ['Workey Labs', 'Head of Talent'],
            'employer.recruiter@workey.test' => ['Workey Labs', 'Technical Recruiter'],
            'employer.pending@workey.test' => ['Pending Ventures', 'Founder'],
            'employer.rejected@workey.test' => ['Rejected Systems', 'HR Manager'],
            'employer.suspended@workey.test' => ['Suspended Digital', 'Recruitment Lead'],
            'employer.second@workey.test' => ['Damascus Data Co.', 'People Operations Manager'],
        ];

        foreach ($links as $index => $link) {
            [$email, $companyName, $jobTitle] = [$index, $link[0], $link[1]];
            EmployerProfile::query()->create([
                'user_id' => User::query()->where('email', $email)->valueOrFail('id'),
                'company_id' => Company::query()->where('name', $companyName)->valueOrFail('id'),
                'job_title' => $jobTitle,
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
    }
}

<?php

namespace Database\Seeders;

use Database\Seeders\Support\DemoDatabaseResetter;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FullProjectSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(DemoDatabaseResetter $resetter): void
    {
        $resetter->assertSafeEnvironment();
        DemoSeederContext::initialize(now()->startOfSecond());
        $resetter->reset();

        $this->call([
            ReferenceDataSeeder::class,
            DemoUsersSeeder::class,
            DemoJobSeekerProfilesSeeder::class,
            DemoCVSeeder::class,
            DemoJobPostingsSeeder::class,
            DemoApplicationsSeeder::class,
            DemoApplicationInformationSeeder::class,
            DemoTestsSeeder::class,
            DemoInterviewsSeeder::class,
            DemoNotificationsSeeder::class,
            DemoAuditLogsSeeder::class,
            DemoRecommendationsSeeder::class,
        ]);

        $this->summary();
    }

    private function summary(): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->newLine();
        $this->command->info('Workey demo database rebuilt successfully.');
        $this->command->table(
            ['Table', 'Records'],
            collect([
                'users',
                'companies',
                'job_seeker_profiles',
                'cv_files',
                'job_postings',
                'job_applications',
                'application_status_histories',
                'application_information_requests',
                'application_test_assignments',
                'test_attempts',
                'interviews',
                'notifications',
                'audit_logs',
                'recommendation_runs',
                'recommendation_items',
            ])->map(fn (string $table): array => [$table, DB::table($table)->count()])->all(),
        );
        $this->command->table(
            ['Role', 'Email', 'Password', 'Status', 'Description'],
            [
                ['Admin', 'admin@workey.test', 'password', 'active', 'Platform administrator'],
                ['Employer', 'employer.approved@workey.test', 'password', 'active', 'Approved Workey Labs employer'],
                ['Employer', 'employer.pending@workey.test', 'password', 'active', 'Pending company employer'],
                ['Job Seeker', 'seeker.backend@workey.test', 'password', 'active', 'Accepted backend candidate scenario'],
                ['Job Seeker', 'seeker.frontend@workey.test', 'password', 'active', 'Frontend candidate scenarios'],
                ['Job Seeker', 'seeker.suspended@workey.test', 'password', 'suspended', 'Suspended account scenario'],
            ],
        );
    }
}

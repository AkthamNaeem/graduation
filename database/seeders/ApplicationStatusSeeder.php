<?php

namespace Database\Seeders;

use App\Models\ApplicationStatus;
use Illuminate\Database\Seeder;

class ApplicationStatusSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        collect([
            ['name' => 'Submitted', 'slug' => 'submitted'],
            ['name' => 'Under Review', 'slug' => 'under_review'],
            ['name' => 'Shortlisted', 'slug' => 'shortlisted'],
            ['name' => 'Test Pending', 'slug' => 'test_pending'],
            ['name' => 'Test Completed', 'slug' => 'test_completed'],
            ['name' => 'Interview Pending', 'slug' => 'interview_pending'],
            ['name' => 'Interview Scheduled', 'slug' => 'interview_scheduled'],
            ['name' => 'Interview Completed', 'slug' => 'interview_completed'],
            ['name' => 'Final Review', 'slug' => 'final_review'],
            ['name' => 'Accepted', 'slug' => 'accepted'],
            ['name' => 'Rejected', 'slug' => 'rejected'],
            ['name' => 'Withdrawn', 'slug' => 'withdrawn'],
            ['name' => 'On Hold', 'slug' => 'on_hold'],
            ['name' => 'Need More Information', 'slug' => 'need_more_information'],
        ])->each(function (array $status): void {
            ApplicationStatus::updateOrCreate(
                ['slug' => $status['slug']],
                ['name' => $status['name']],
            );
        });
    }
}

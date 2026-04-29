<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Education;
use App\Models\EmployerProfile;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SampleUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@smartrecruitment.test'],
            [
                'name' => 'Platform Admin',
                'role' => UserRole::ADMIN,
                'password' => 'password',
            ],
        );

        $jobSeeker = User::updateOrCreate(
            ['email' => 'jobseeker@smartrecruitment.test'],
            [
                'name' => 'Sample Job Seeker',
                'role' => UserRole::JOB_SEEKER,
                'password' => 'password',
            ],
        );

        $profile = JobSeekerProfile::updateOrCreate(
            ['user_id' => $jobSeeker->id],
            [
                'headline' => 'Laravel Backend Developer',
                'summary' => 'Backend developer focused on REST APIs, Laravel, MySQL, and clean service-oriented application architecture.',
                'phone' => '+1 555 0100',
                'location' => 'Remote',
                'portfolio_url' => 'https://jane-applicant.example.com',
                'linkedin_url' => 'https://www.linkedin.com/in/jane-applicant',
                'github_url' => 'https://github.com/jane-applicant',
            ],
        );

        $company = Company::updateOrCreate(
            ['name' => 'Acme Hiring Co.'],
            [
                'industry' => 'Recruitment Technology',
                'website' => 'https://acme-hiring.example.com',
                'location' => 'New York, NY',
                'description' => 'A sample company used for employer profile testing.',
            ],
        );

        $employer = User::updateOrCreate(
            ['email' => 'employer@smartrecruitment.test'],
            [
                'name' => 'Sample Employer',
                'role' => UserRole::EMPLOYER,
                'password' => 'password',
            ],
        );

        EmployerProfile::updateOrCreate(
            ['user_id' => $employer->id],
            [
                'company_id' => $company->id,
                'job_title' => 'Talent Acquisition Manager',
                'phone' => '+1 555 0199',
                'bio' => 'Hiring manager responsible for backend engineering roles.',
            ],
        );

        Experience::updateOrCreate(
            [
                'job_seeker_profile_id' => $profile->id,
                'title' => 'Backend Developer',
                'company_name' => 'Northwind Software',
            ],
            [
                'location' => 'Remote',
                'start_date' => '2023-01-01',
                'end_date' => null,
                'is_current' => true,
                'description' => 'Built Laravel REST APIs, authentication flows, and MySQL-backed reporting services.',
            ],
        );

        Education::updateOrCreate(
            [
                'job_seeker_profile_id' => $profile->id,
                'institution' => 'State University',
            ],
            [
                'degree' => 'Bachelor of Science',
                'field_of_study' => 'Computer Science',
                'start_date' => '2018-09-01',
                'end_date' => '2022-06-01',
                'description' => 'Focused on software engineering, databases, and web application development.',
            ],
        );

        $skillNames = [
            'PHP',
            'Laravel',
            'MySQL',
            'REST APIs',
            'JavaScript',
            'Vue.js',
            'React',
            'Git',
            'Docker',
            'AWS',
            'Communication',
            'Problem Solving',
            'Testing',
            'Agile',
            'API Design',
        ];

        $skills = collect($skillNames)->map(fn (string $name): Skill => Skill::updateOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        ));

        $profile->skills()->syncWithoutDetaching(
            $skills->whereIn('name', ['PHP', 'Laravel', 'MySQL', 'REST APIs', 'Git'])->pluck('id')->all(),
        );

        $admin->tokens()->delete();
        $jobSeeker->tokens()->delete();
        $employer->tokens()->delete();
    }
}

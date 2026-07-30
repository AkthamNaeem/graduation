<?php

namespace Database\Seeders;

use App\Models\Education;
use App\Models\Experience;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;

class DemoJobSeekerProfilesSeeder extends Seeder
{
    /** @var array<string, array<string, mixed>> */
    private const PROFILES = [
        'seeker.backend@workey.test' => [
            'headline' => 'Laravel Backend Developer',
            'summary' => 'Backend engineer building reliable Laravel APIs, relational data models, and automated tests.',
            'location' => 'Damascus, Syria',
            'skills' => ['PHP', 'Laravel', 'MySQL', 'PostgreSQL', 'REST APIs', 'Git', 'Docker', 'Testing', 'API Design'],
            'source_type' => 'manual',
            'experience' => ['Backend Developer', 'Levant Software', '2021-01-01', null, true],
            'education' => ['Damascus University', 'Bachelor', 'Computer Science', '2016-09-01', '2020-07-01'],
        ],
        'seeker.frontend@workey.test' => [
            'headline' => 'Frontend Product Engineer',
            'summary' => 'Frontend developer specializing in React, TypeScript, accessibility, and design systems.',
            'location' => 'Aleppo, Syria',
            'skills' => ['JavaScript', 'TypeScript', 'React', 'Vue.js', 'Git', 'Testing', 'Communication'],
            'source_type' => 'cv_parsed',
            'experience' => ['Frontend Engineer', 'Pixel Works', '2022-03-01', null, true],
            'education' => ['Aleppo University', 'Bachelor', 'Software Engineering', '2017-09-01', '2021-07-01'],
        ],
        'seeker.data@workey.test' => [
            'headline' => 'Data and Machine Learning Engineer',
            'summary' => 'Python engineer developing ranked recommendation systems and production data pipelines.',
            'location' => 'Remote',
            'skills' => ['Python', 'Machine Learning', 'PostgreSQL', 'Docker', 'AWS', 'Problem Solving'],
            'source_type' => 'cv_merged',
            'experience' => ['Machine Learning Engineer', 'Data Horizon', '2020-06-01', null, true],
            'education' => ['Higher Institute for Applied Sciences', 'Master', 'Artificial Intelligence', '2018-09-01', '2020-06-01'],
        ],
        'seeker.junior@workey.test' => [
            'headline' => 'Junior Software Developer',
            'summary' => 'Early-career developer with internship experience and strong learning habits.',
            'location' => 'Homs, Syria',
            'skills' => ['PHP', 'JavaScript', 'Git', 'Communication', 'Agile'],
            'source_type' => 'user_verified',
            'experience' => ['Software Engineering Intern', 'Start Syria', '2025-01-01', '2025-08-01', false],
            'education' => ['Al-Baath University', 'Bachelor', 'Information Technology', '2021-09-01', '2025-07-01'],
        ],
        'seeker.senior@workey.test' => [
            'headline' => 'Senior Platform Engineer',
            'summary' => 'Senior engineer leading cloud platforms, API architecture, delivery, and mentoring.',
            'location' => 'Dubai, UAE',
            'skills' => ['PHP', 'Laravel', 'PostgreSQL', 'REST APIs', 'Git', 'Docker', 'AWS', 'Testing', 'Agile', 'Communication'],
            'source_type' => 'system_generated',
            'experience' => ['Senior Platform Engineer', 'Cloud Bridge', '2015-02-01', null, true],
            'education' => ['Tishreen University', 'Bachelor', 'Computer Engineering', '2009-09-01', '2014-07-01'],
        ],
        'seeker.incomplete@workey.test' => [
            'headline' => null,
            'summary' => null,
            'location' => null,
            'skills' => ['Problem Solving'],
            'source_type' => 'manual',
            'experience' => null,
            'education' => null,
        ],
        'seeker.suspended@workey.test' => [
            'headline' => 'QA Automation Engineer',
            'summary' => 'Suspended demo account retained to exercise account-state authorization.',
            'location' => 'Latakia, Syria',
            'skills' => ['Testing', 'Git', 'Problem Solving'],
            'source_type' => 'manual',
            'experience' => ['QA Engineer', 'Quality First', '2019-01-01', null, true],
            'education' => ['Tishreen University', 'Diploma', 'Software Testing', '2016-09-01', '2018-07-01'],
        ],
    ];

    public function run(): void
    {
        $now = DemoSeederContext::now();

        foreach (self::PROFILES as $email => $data) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $profile = JobSeekerProfile::query()->create([
                'user_id' => $user->id,
                'headline' => $data['headline'],
                'summary' => $data['summary'],
                'phone' => '+963 9'.str_pad((string) $user->id, 8, '0', STR_PAD_LEFT),
                'location' => $data['location'],
                'portfolio_url' => $data['headline'] === null ? null : 'https://portfolio.example.test/'.str($email)->before('@'),
                'linkedin_url' => $data['headline'] === null ? null : 'https://linkedin.example.test/in/'.str($email)->before('@'),
                'github_url' => str_contains($email, 'backend') || str_contains($email, 'frontend')
                    ? 'https://github.example.test/'.str($email)->before('@')
                    : null,
                'created_at' => $now->copy()->subDays(45),
                'updated_at' => $now->copy()->subDay(),
            ]);

            if (is_array($data['experience'])) {
                [$title, $company, $start, $end, $current] = $data['experience'];
                Experience::query()->create([
                    'job_seeker_profile_id' => $profile->id,
                    'title' => $title,
                    'company_name' => $company,
                    'location' => $data['location'],
                    'start_date' => $start,
                    'end_date' => $end,
                    'is_current' => $current,
                    'description' => "Representative {$title} experience for the demo workflow.",
                    'source_type' => $data['source_type'],
                    'user_verified_at' => $data['source_type'] === 'user_verified' ? $now->copy()->subDays(3) : null,
                ]);
            }

            if (is_array($data['education'])) {
                [$institution, $degree, $field, $start, $end] = $data['education'];
                Education::query()->create([
                    'job_seeker_profile_id' => $profile->id,
                    'institution' => $institution,
                    'degree' => $degree,
                    'field_of_study' => $field,
                    'start_date' => $start,
                    'end_date' => $end,
                    'description' => "Demo {$field} education record.",
                    'source_type' => $data['source_type'],
                    'user_verified_at' => $data['source_type'] === 'user_verified' ? $now->copy()->subDays(3) : null,
                ]);
            }

            $skillIds = Skill::query()->whereIn('name', $data['skills'])->pluck('id')->all();
            $profile->skills()->sync(collect($skillIds)->mapWithKeys(fn (int $skillId): array => [
                $skillId => [
                    'source_type' => $data['source_type'],
                    'source_cv_file_id' => null,
                    'user_verified_at' => $data['source_type'] === 'user_verified' ? $now->copy()->subDays(3) : null,
                ],
            ])->all());
        }
    }
}

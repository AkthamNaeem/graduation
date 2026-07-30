<?php

namespace Database\Seeders;

use App\Enums\EducationLevel;
use App\Enums\JobSkillRequirementType;
use App\Enums\JobWorkMode;
use App\Enums\ScreeningQuestionType;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\Support\DemoSeederContext;
use Illuminate\Database\Seeder;

class DemoJobPostingsSeeder extends Seeder
{
    public function run(): void
    {
        $now = DemoSeederContext::now();
        $workey = Company::query()->where('name', 'Workey Labs')->firstOrFail();
        $dataCompany = Company::query()->where('name', 'Damascus Data Co.')->firstOrFail();
        $pendingCompany = Company::query()->where('name', 'Pending Ventures')->firstOrFail();

        $jobs = [
            [
                'company' => $workey,
                'title' => 'Senior Laravel Backend Engineer',
                'employment_type' => 'full-time',
                'experience_level' => 'senior',
                'education_level' => EducationLevel::BACHELOR,
                'work_mode' => JobWorkMode::REMOTE,
                'location' => null,
                'status' => 'open',
                'published_at' => $now->copy()->subDays(10),
                'deadline' => $now->copy()->addDays(30),
                'salary' => [1800, 2600],
                'skills' => [
                    'PHP' => [JobSkillRequirementType::REQUIRED, 5],
                    'Laravel' => [JobSkillRequirementType::REQUIRED, 5],
                    'MySQL' => [JobSkillRequirementType::REQUIRED, 4],
                    'REST APIs' => [JobSkillRequirementType::REQUIRED, 4],
                    'Testing' => [JobSkillRequirementType::REQUIRED, 3],
                    'Docker' => [JobSkillRequirementType::NICE_TO_HAVE, 2],
                    'AWS' => [JobSkillRequirementType::NICE_TO_HAVE, 1],
                ],
            ],
            [
                'company' => $workey,
                'title' => 'Frontend Product Engineer',
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
                'education_level' => EducationLevel::DIPLOMA,
                'work_mode' => JobWorkMode::HYBRID,
                'location' => 'Damascus, Syria',
                'status' => 'draft',
                'published_at' => null,
                'deadline' => null,
                'salary' => [1300, 1900],
                'skills' => [
                    'JavaScript' => [JobSkillRequirementType::REQUIRED, 5],
                    'TypeScript' => [JobSkillRequirementType::REQUIRED, 4],
                    'React' => [JobSkillRequirementType::REQUIRED, 4],
                    'Vue.js' => [JobSkillRequirementType::OPTIONAL, 2],
                ],
            ],
            [
                'company' => $workey,
                'title' => 'Technical Recruiter',
                'employment_type' => 'contract',
                'experience_level' => 'mid',
                'education_level' => EducationLevel::HIGH_SCHOOL,
                'work_mode' => JobWorkMode::ON_SITE,
                'location' => 'Damascus, Syria',
                'status' => 'closed',
                'published_at' => $now->copy()->subDays(40),
                'deadline' => $now->copy()->subDays(2),
                'salary' => [900, 1300],
                'skills' => [
                    'Communication' => [JobSkillRequirementType::REQUIRED, 5],
                    'Problem Solving' => [JobSkillRequirementType::NICE_TO_HAVE, 2],
                ],
            ],
            [
                'company' => $dataCompany,
                'title' => 'Machine Learning Engineer',
                'employment_type' => 'full-time',
                'experience_level' => 'senior',
                'education_level' => EducationLevel::MASTER,
                'work_mode' => JobWorkMode::REMOTE,
                'location' => null,
                'status' => 'open',
                'published_at' => $now->copy()->subDays(8),
                'deadline' => null,
                'salary' => [2200, 3100],
                'skills' => [
                    'Python' => [JobSkillRequirementType::REQUIRED, 5],
                    'Machine Learning' => [JobSkillRequirementType::REQUIRED, 5],
                    'PostgreSQL' => [JobSkillRequirementType::NICE_TO_HAVE, 2],
                    'Docker' => [JobSkillRequirementType::NICE_TO_HAVE, 2],
                ],
            ],
            [
                'company' => $dataCompany,
                'title' => 'AI Research Intern',
                'employment_type' => 'internship',
                'experience_level' => 'entry',
                'education_level' => EducationLevel::DOCTORATE,
                'work_mode' => JobWorkMode::ON_SITE,
                'location' => 'Damascus, Syria',
                'status' => 'open',
                'published_at' => $now->copy()->subDays(4),
                'deadline' => $now->copy()->addDays(12),
                'salary' => [null, null],
                'skills' => [
                    'Python' => [JobSkillRequirementType::REQUIRED, 3],
                    'Machine Learning' => [JobSkillRequirementType::NICE_TO_HAVE, 2],
                ],
            ],
            [
                'company' => $workey,
                'title' => 'Part-time API Support Engineer',
                'employment_type' => 'part-time',
                'experience_level' => 'junior',
                'education_level' => null,
                'work_mode' => JobWorkMode::REMOTE,
                'location' => null,
                'status' => 'open',
                'published_at' => $now->copy()->subDays(6),
                'deadline' => $now->copy()->addDays(20),
                'salary' => [600, 900],
                'skills' => [
                    'REST APIs' => [JobSkillRequirementType::REQUIRED, 4],
                    'Communication' => [JobSkillRequirementType::REQUIRED, 3],
                ],
            ],
            [
                'company' => $pendingCompany,
                'title' => 'Pending Company Developer',
                'employment_type' => 'full-time',
                'experience_level' => 'mid-level',
                'education_level' => EducationLevel::BACHELOR,
                'work_mode' => JobWorkMode::HYBRID,
                'location' => 'Damascus, Syria',
                'status' => 'open',
                'published_at' => $now->copy()->subDays(5),
                'deadline' => $now->copy()->addDays(25),
                'salary' => [1200, 1600],
                'skills' => [
                    'PHP' => [JobSkillRequirementType::REQUIRED, 4],
                    'Laravel' => [JobSkillRequirementType::REQUIRED, 4],
                ],
            ],
            [
                'company' => $workey,
                'title' => 'Expired Mobile API Contract',
                'employment_type' => 'contract',
                'experience_level' => 'mid-level',
                'education_level' => EducationLevel::BACHELOR,
                'work_mode' => JobWorkMode::REMOTE,
                'location' => null,
                'status' => 'open',
                'published_at' => $now->copy()->subDays(30),
                'deadline' => $now->copy()->subDay(),
                'salary' => [1500, 1800],
                'skills' => [
                    'REST APIs' => [JobSkillRequirementType::REQUIRED, 5],
                    'Docker' => [JobSkillRequirementType::NICE_TO_HAVE, 2],
                ],
            ],
        ];

        foreach ($jobs as $data) {
            $job = JobPosting::query()->create([
                'company_id' => $data['company']->id,
                'title' => $data['title'],
                'department' => str_contains($data['title'], 'Recruiter') ? 'People' : 'Engineering',
                'description' => "Demo vacancy for {$data['title']} used by the complete recruitment workflow.",
                'responsibilities' => 'Deliver measurable outcomes, collaborate with the team, and document decisions.',
                'requirements' => 'Relevant professional experience and evidence of practical delivery.',
                'benefits' => $data['salary'][0] === null ? null : 'Flexible hours, learning budget, and health coverage.',
                'employment_type' => $data['employment_type'],
                'experience_level' => $data['experience_level'],
                'education_level' => $data['education_level']?->value,
                'location' => $data['location'],
                'work_mode' => $data['work_mode'],
                'salary_min' => $data['salary'][0],
                'salary_max' => $data['salary'][1],
                'status' => $data['status'],
                'published_at' => $data['published_at'],
                'application_deadline' => $data['deadline'],
                'created_at' => $data['published_at'] ?? $now->copy()->subDays(3),
                'updated_at' => $now->copy()->subDay(),
            ]);

            $job->skills()->sync(collect($data['skills'])->mapWithKeys(function (array $settings, string $skillName): array {
                $skillId = Skill::query()->where('name', $skillName)->valueOrFail('id');

                return [$skillId => [
                    'requirement_type' => $settings[0]->value,
                    'weight' => $settings[1],
                ]];
            })->all());
        }

        $this->screeningQuestions(
            JobPosting::query()->where('title', 'Senior Laravel Backend Engineer')->firstOrFail(),
            User::query()->where('email', 'employer.approved@workey.test')->firstOrFail(),
        );
    }

    private function screeningQuestions(JobPosting $job, User $creator): void
    {
        $questions = [
            [ScreeningQuestionType::SHORT_TEXT, 'Describe your strongest Laravel project.', []],
            [ScreeningQuestionType::LONG_TEXT, 'Explain how you design a resilient API.', []],
            [ScreeningQuestionType::NUMBER, 'How many years of Laravel experience do you have?', []],
            [ScreeningQuestionType::BOOLEAN, 'Can you work with a distributed team?', []],
            [ScreeningQuestionType::SINGLE_CHOICE, 'Choose your preferred work schedule.', ['Morning', 'Evening']],
            [ScreeningQuestionType::MULTIPLE_CHOICE, 'Select the databases you have used.', ['MySQL', 'PostgreSQL', 'SQLite']],
        ];

        foreach ($questions as $index => [$type, $text, $options]) {
            $question = JobScreeningQuestion::query()->create([
                'job_posting_id' => $job->id,
                'question_text' => $text,
                'question_type' => $type,
                'is_required' => $index !== 1,
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_by_user_id' => $creator->id,
            ]);

            foreach ($options as $optionIndex => $option) {
                $question->options()->create([
                    'option_text' => $option,
                    'sort_order' => $optionIndex + 1,
                ]);
            }
        }
    }
}

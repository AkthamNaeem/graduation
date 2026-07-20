<?php

namespace Database\Factories;

use App\Enums\EducationLevel;
use App\Enums\JobWorkMode;
use App\Models\Company;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobPosting> */
class JobPostingFactory extends Factory
{
    protected $model = JobPosting::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => fake()->jobTitle(),
            'description' => fake()->paragraph(),
            'requirements' => fake()->sentence(),
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'education_level' => fake()->optional()->randomElement(EducationLevel::cases())?->value,
            'location' => null,
            'work_mode' => JobWorkMode::REMOTE,
            'status' => 'draft',
        ];
    }
}

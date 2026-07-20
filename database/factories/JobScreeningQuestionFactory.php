<?php

namespace Database\Factories;

use App\Enums\ScreeningQuestionType;
use App\Models\JobPosting;
use App\Models\JobScreeningQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobScreeningQuestion> */
class JobScreeningQuestionFactory extends Factory
{
    protected $model = JobScreeningQuestion::class;

    public function definition(): array
    {
        return [
            'job_posting_id' => JobPosting::factory(),
            'question_text' => fake()->sentence().'?',
            'question_type' => ScreeningQuestionType::SHORT_TEXT,
            'is_required' => false,
            'sort_order' => 0,
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\JobScreeningQuestion;
use App\Models\JobScreeningQuestionOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<JobScreeningQuestionOption> */
class JobScreeningQuestionOptionFactory extends Factory
{
    protected $model = JobScreeningQuestionOption::class;

    public function definition(): array
    {
        return [
            'job_screening_question_id' => JobScreeningQuestion::factory(),
            'option_text' => fake()->words(2, true),
            'sort_order' => 0,
        ];
    }
}

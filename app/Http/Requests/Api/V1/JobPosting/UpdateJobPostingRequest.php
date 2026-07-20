<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Enums\EducationLevel;
use App\Enums\JobWorkMode;
use App\Models\JobPosting;
use Illuminate\Validation\Rule;

class UpdateJobPostingRequest extends StoreJobPostingRequest
{
    public function authorize(): bool
    {
        $jobPosting = $this->route('jobPosting');

        return $jobPosting instanceof JobPosting
            && $this->isEmployerUser()
            && $this->authenticatedUser()?->can('update', $jobPosting);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'responsibilities' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'requirements' => ['sometimes', 'required', 'string', 'max:20000'],
            'benefits' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'employment_type' => ['sometimes', 'required', 'string', 'max:255'],
            'experience_level' => ['sometimes', 'required', 'string', 'max:255'],
            'education_level' => ['sometimes', 'nullable', Rule::enum(EducationLevel::class)],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_mode' => ['sometimes', 'required', Rule::enum(JobWorkMode::class)],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'application_deadline' => ['sometimes', 'nullable', 'date', 'after:now'],
            ...$this->jobSkillRules(),
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Enums\JobSkillRequirementType;
use App\Enums\JobWorkMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class IndexJobPostingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->input('skill_requirement') === JobSkillRequirementType::OPTIONAL->value) {
            $this->merge(['skill_requirement' => JobSkillRequirementType::NICE_TO_HAVE->value]);
        }

        if ($this->has('accepting_applications')) {
            $value = filter_var($this->input('accepting_applications'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $this->merge(['accepting_applications' => $value]);
            }
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'skill' => ['sometimes', 'nullable', 'string', 'max:255'],
            'experience_level' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employment_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_mode' => ['sometimes', 'nullable', Rule::enum(JobWorkMode::class)],
            'accepting_applications' => ['sometimes', 'boolean'],
            'skill_requirement' => ['sometimes', 'in:required,nice_to_have'],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sort_by' => ['sometimes', 'nullable', 'string', 'in:published_at,created_at,salary_min,salary_max,title,application_deadline'],
            'sort_direction' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('skill_requirement') && ! $this->filled('skill')) {
                $validator->errors()->add('skill_requirement', 'The skill requirement filter requires the skill filter.');
            }

            if (! $this->filled('salary_min') || ! $this->filled('salary_max')) {
                return;
            }

            if ((float) $this->input('salary_max') < (float) $this->input('salary_min')) {
                $validator->errors()->add('salary_max', 'The salary max field must be greater than or equal to salary min.');
            }
        });
    }
}

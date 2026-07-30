<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Enums\EducationLevel;
use App\Enums\JobWorkMode;
use App\Enums\UserRole;
use App\Http\Requests\Api\V1\JobPosting\Concerns\NormalizesJobSkillInput;
use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

class StoreJobPostingRequest extends FormRequest
{
    use NormalizesJobSkillInput;
    use ResolvesJobPostingUser;

    protected function prepareForValidation(): void
    {
        $this->normalizeJobSkillInput();
        $deadline = $this->input('application_deadline');
        if (! is_string($deadline)) {
            return;
        }

        try {
            $this->merge([
                'application_deadline' => Carbon::parse($deadline)->utc()->toISOString(),
            ]);
        } catch (Throwable) {
            // Keep the original input so the date validation rule returns the API error.
        }
    }

    public function authorize(): bool
    {
        return $this->isEmployerUser()
            && $this->authenticatedUser()?->can('create', JobPosting::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->authenticatedUser()?->role === UserRole::ADMIN
                ? ['required', 'integer', 'exists:companies,id']
                : ['prohibited'],
            'title' => ['required', 'string', 'max:255'],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'responsibilities' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'requirements' => ['required', 'string', 'max:20000'],
            'benefits' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'employment_type' => ['required', 'string', 'max:255'],
            'experience_level' => ['required', 'string', 'max:255'],
            'education_level' => ['sometimes', 'nullable', Rule::enum(EducationLevel::class)],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'work_mode' => ['required', Rule::enum(JobWorkMode::class)],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'application_deadline' => ['sometimes', 'nullable', 'date', 'after:now'],
            ...$this->jobSkillRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateJobSkillContracts($validator);
            $jobPosting = $this->route('jobPosting');
            $salaryMin = $this->filled('salary_min')
                ? (float) $this->input('salary_min')
                : $jobPosting?->salary_min;
            $salaryMax = $this->filled('salary_max')
                ? (float) $this->input('salary_max')
                : $jobPosting?->salary_max;

            if ($salaryMin !== null && $salaryMax !== null && $salaryMax < $salaryMin) {
                $validator->errors()->add('salary_max', __('validation.custom_messages.salary_range'));
            }

            $workModeValue = $this->filled('work_mode')
                ? (string) $this->input('work_mode')
                : ($jobPosting?->work_mode?->value ?? $jobPosting?->work_mode);
            $location = $this->exists('location') ? $this->input('location') : $jobPosting?->location;
            $workMode = JobWorkMode::tryFrom((string) $workModeValue);
            if ($workMode?->requiresLocation() && blank($location)) {
                $validator->errors()->add('location', __('validation.custom_messages.job_location_required'));
            }
        });
    }
}

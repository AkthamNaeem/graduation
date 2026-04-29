<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreJobPostingRequest extends FormRequest
{
    use ResolvesJobPostingUser;

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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'employment_type' => ['required', 'string', 'max:255'],
            'experience_level' => ['required', 'string', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $jobPosting = $this->route('jobPosting');
            $salaryMin = $this->filled('salary_min')
                ? (float) $this->input('salary_min')
                : $jobPosting?->salary_min;
            $salaryMax = $this->filled('salary_max')
                ? (float) $this->input('salary_max')
                : $jobPosting?->salary_max;

            if ($salaryMin !== null && $salaryMax !== null && $salaryMax < $salaryMin) {
                $validator->errors()->add('salary_max', 'The salary max field must be greater than or equal to salary min.');
            }
        });
    }
}

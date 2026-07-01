<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IndexJobPostingRequest extends FormRequest
{
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
            'salary_min' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_max' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'sort_by' => ['sometimes', 'nullable', 'string', 'in:published_at,created_at,salary_min,salary_max,title'],
            'sort_direction' => ['sometimes', 'nullable', 'string', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('salary_min') || ! $this->filled('salary_max')) {
                return;
            }

            if ((float) $this->input('salary_max') < (float) $this->input('salary_min')) {
                $validator->errors()->add('salary_max', 'The salary max field must be greater than or equal to salary min.');
            }
        });
    }
}

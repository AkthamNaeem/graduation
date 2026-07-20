<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobApplicationRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        return $this->isJobSeekerUser();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cv_file_id' => ['sometimes', 'nullable', 'integer'],
            'selected_cv_file_id' => [
                'sometimes',
                'nullable',
                'integer',
            ],
            'cover_letter' => ['nullable', 'string', 'max:10000'],
            'consent_to_share_profile' => ['required', 'boolean', Rule::in([true])],
            'screening_answers' => ['sometimes', 'array', 'list', 'max:50'],
            'screening_answers.*' => ['required', 'array:question_id,value,selected_option_ids'],
            'screening_answers.*.question_id' => ['required', 'integer', 'distinct'],
            'screening_answers.*.value' => ['sometimes'],
            'screening_answers.*.selected_option_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:50'],
            'screening_answers.*.selected_option_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('cv_file_id') && ! $this->has('selected_cv_file_id')) {
            $this->merge(['selected_cv_file_id' => $this->input('cv_file_id')]);
        }
        if ($this->has('consent') && ! $this->has('consent_to_share_profile')) {
            $this->merge(['consent_to_share_profile' => $this->input('consent')]);
        }
        if ($this->has('cover_letter') && is_string($this->input('cover_letter'))) {
            $coverLetter = trim($this->input('cover_letter'));
            $this->merge(['cover_letter' => $coverLetter === '' ? null : $coverLetter]);
        }
    }
}

<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Enums\ScreeningQuestionType;
use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreJobScreeningQuestionRequest extends FormRequest
{
    use ResolvesJobPostingUser;

    public function authorize(): bool
    {
        $jobPosting = $this->route('jobPosting');

        return $jobPosting instanceof JobPosting
            && $this->isEmployerUser()
            && ($this->authenticatedUser()?->can('manageScreeningQuestions', $jobPosting) ?? false);
    }

    public function rules(): array
    {
        return [
            'question_text' => ['required', 'string', 'max:2000'],
            'question_type' => ['required', Rule::enum(ScreeningQuestionType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'options' => ['sometimes', 'array', 'min:2', 'max:50'],
            'options.*' => ['required', 'array:option_text,sort_order'],
            'options.*.option_text' => ['required', 'string', 'max:1000'],
            'options.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        if ($this->has('question_text') && is_string($this->input('question_text'))) {
            $data['question_text'] = trim($this->input('question_text'));
        }
        if (is_array($this->input('options'))) {
            $data['options'] = array_map(static function (mixed $option): mixed {
                if (is_array($option) && isset($option['option_text']) && is_string($option['option_text'])) {
                    $option['option_text'] = trim($option['option_text']);
                }

                return $option;
            }, $this->input('options'));
        }
        if ($data !== []) {
            $this->merge($data);
        }
    }
}

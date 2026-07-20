<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Enums\ScreeningQuestionType;
use Illuminate\Validation\Rule;

class UpdateJobScreeningQuestionRequest extends StoreJobScreeningQuestionRequest
{
    public function rules(): array
    {
        return [
            'question_text' => ['sometimes', 'required', 'string', 'max:2000'],
            'question_type' => ['sometimes', 'required', Rule::enum(ScreeningQuestionType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'options' => ['sometimes', 'array', 'min:2', 'max:50'],
            'options.*' => ['required', 'array:option_text,sort_order'],
            'options.*.option_text' => ['required', 'string', 'max:1000'],
            'options.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

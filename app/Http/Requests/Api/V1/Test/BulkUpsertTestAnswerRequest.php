<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestAnswers;
use Illuminate\Foundation\Http\FormRequest;

class BulkUpsertTestAnswerRequest extends FormRequest
{
    use AuthorizesTestAnswers;

    public function authorize(): bool
    {
        return $this->canManageAnswers();
    }

    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer', 'distinct'],
            'answers.*.answer_text' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'answers.*.selected_option_ids' => ['sometimes', 'array'],
            'answers.*.selected_option_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}

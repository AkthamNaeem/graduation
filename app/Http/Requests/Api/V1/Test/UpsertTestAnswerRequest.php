<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestAnswers;
use Illuminate\Foundation\Http\FormRequest;

class UpsertTestAnswerRequest extends FormRequest
{
    use AuthorizesTestAnswers;

    public function authorize(): bool
    {
        return $this->canManageAnswers();
    }

    public function rules(): array
    {
        return [
            'answer_text' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'selected_option_ids' => ['sometimes', 'array'],
            'selected_option_ids.*' => ['required', 'integer', 'distinct'],
            'answer_file' => [
                'sometimes',
                'file',
                'max:10240',
                'mimes:pdf,doc,docx,txt,zip,png,jpg,jpeg',
                'extensions:pdf,doc,docx,txt,zip,png,jpg,jpeg',
            ],
        ];
    }
}

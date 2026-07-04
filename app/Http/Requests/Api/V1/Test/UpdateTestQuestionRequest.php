<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Models\TestQuestion;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTestQuestionRequest extends StoreTestQuestionRequest
{
    public function authorize(): bool
    {
        return $this->route('testQuestion') instanceof TestQuestion
            && $this->canManageTestCatalog();
    }

    public function rules(): array
    {
        return [
            'question_text' => ['sometimes', 'required', 'string'],
            'question_type' => ['sometimes', 'required', 'string', Rule::in(['single_choice', 'multiple_choice', 'short_text', 'long_text'])],
            'points' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'order_index' => ['sometimes', 'integer', 'min:0'],
            'is_required' => ['sometimes', 'boolean'],
            'expected_answer' => ['sometimes', 'nullable', 'string'],
            'explanation' => ['sometimes', 'nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            'options.*.option_text' => ['required_with:options', 'string'],
            'options.*.is_correct' => ['sometimes', 'boolean'],
            'options.*.order_index' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('options') && ! $this->has('question_type')) {
                return;
            }

            $question = $this->route('testQuestion');
            $type = $this->input('question_type', $question instanceof TestQuestion ? $question->question_type : null);
            $options = $this->input('options', []);

            if ($this->has('options') && in_array($type, ['single_choice', 'multiple_choice'], true)) {
                if (! is_array($options) || count($options) < 2) {
                    $validator->errors()->add('options', 'Choice questions require at least two options.');
                    return;
                }

                $correctCount = collect($options)->filter(fn (array $option): bool => (bool) ($option['is_correct'] ?? false))->count();

                if ($type === 'single_choice' && $correctCount !== 1) {
                    $validator->errors()->add('options', 'Single choice questions require exactly one correct option.');
                }

                if ($type === 'multiple_choice' && $correctCount < 1) {
                    $validator->errors()->add('options', 'Multiple choice questions require at least one correct option.');
                }
            }
        });
    }
}

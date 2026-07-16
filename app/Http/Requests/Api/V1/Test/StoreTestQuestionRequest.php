<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Enums\TestQuestionType;
use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestStructure;
use App\Services\TestQuestionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTestQuestionRequest extends FormRequest
{
    use AuthorizesTestStructure;

    public function authorize(): bool { return $this->canManageTestStructure(); }

    public function rules(): array
    {
        $testId = $this->authorizedTest()?->id;

        return [
            'question_text' => ['required', 'string'],
            'question_type' => ['required', 'string', Rule::enum(TestQuestionType::class)],
            'order_index' => ['required', 'integer', 'min:0', Rule::unique('test_questions', 'order_index')->where('test_id', $testId)],
            'points' => ['required', 'numeric', 'min:0', 'max:1000'],
            'is_required' => ['required', 'boolean'],
            'options' => ['sometimes', 'array'],
            'options.*.option_text' => ['required', 'string'],
            'options.*.order_index' => ['required', 'integer', 'min:0'],
            'options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                app(TestQuestionService::class)->validateOptionSet(
                    TestQuestionType::from((string) $this->input('question_type')),
                    $this->input('options', []),
                );
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        });
    }
}

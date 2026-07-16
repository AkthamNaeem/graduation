<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Enums\TestQuestionType;
use App\Models\TestQuestion;
use App\Services\TestQuestionService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTestQuestionRequest extends StoreTestQuestionRequest
{
    public function authorize(): bool { return $this->canManageTestStructure(requireQuestion: true); }

    public function rules(): array
    {
        $testId = $this->authorizedTest()?->id;
        $questionId = $this->authorizedQuestion()?->id;

        return [
            'question_text' => ['sometimes', 'required', 'string'],
            'question_type' => ['sometimes', 'required', 'string', Rule::enum(TestQuestionType::class)],
            'order_index' => ['sometimes', 'required', 'integer', 'min:0', Rule::unique('test_questions', 'order_index')->where('test_id', $testId)->ignore($questionId)],
            'points' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1000'],
            'is_required' => ['sometimes', 'required', 'boolean'],
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

            $question = $this->authorizedQuestion();
            if (! $question instanceof TestQuestion) {
                return;
            }

            $type = $this->filled('question_type')
                ? TestQuestionType::from((string) $this->input('question_type'))
                : $question->question_type;
            $options = $this->has('options')
                ? $this->input('options', [])
                : $question->options()->get()->map->only(['option_text', 'order_index', 'is_correct'])->all();

            try {
                app(TestQuestionService::class)->validateOptionSet($type, $options);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) { $validator->errors()->add($field, $message); }
                }
            }
        });
    }
}

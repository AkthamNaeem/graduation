<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestAnswers;
use Illuminate\Foundation\Http\FormRequest;

class BulkManualTestAnswerGradingRequest extends FormRequest
{
    use AuthorizesTestAnswers;

    public function authorize(): bool
    {
        return $this->canManageManualGradings();
    }

    public function rules(): array
    {
        return [
            'gradings' => ['required', 'array', 'min:1'],
            'gradings.*.question_id' => ['required', 'integer', 'distinct'],
            'gradings.*.awarded_points' => ['required', 'numeric', 'min:0'],
            'gradings.*.reviewer_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('gradings'))) {
            return;
        }

        $gradings = array_map(function ($grading): mixed {
            if (is_array($grading) && isset($grading['reviewer_note']) && is_string($grading['reviewer_note'])) {
                $grading['reviewer_note'] = trim($grading['reviewer_note']);
            }

            return $grading;
        }, $this->input('gradings'));
        $this->merge(['gradings' => $gradings]);
    }
}

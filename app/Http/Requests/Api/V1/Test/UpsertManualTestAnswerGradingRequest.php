<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestAnswers;
use Illuminate\Foundation\Http\FormRequest;

class UpsertManualTestAnswerGradingRequest extends FormRequest
{
    use AuthorizesTestAnswers;

    public function authorize(): bool
    {
        return $this->canManageManualGradings();
    }

    public function rules(): array
    {
        return [
            'awarded_points' => ['required', 'numeric', 'min:0'],
            'reviewer_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reviewer_note') && is_string($this->input('reviewer_note'))) {
            $this->merge(['reviewer_note' => trim($this->input('reviewer_note'))]);
        }
    }
}

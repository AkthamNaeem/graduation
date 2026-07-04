<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Models\Test;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateTestCatalogRequest extends StoreTestCatalogRequest
{
    public function authorize(): bool
    {
        return $this->route('test') instanceof Test
            && $this->canManageTestCatalog();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'visibility' => ['sometimes', 'string', Rule::in(['company', 'global'])],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1'],
            'max_score' => ['sometimes', 'required', 'numeric', 'min:1'],
            'passing_score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $test = $this->route('test');

            if (! $test instanceof Test) {
                return;
            }

            $passingScore = $this->has('passing_score')
                ? $this->input('passing_score')
                : $test->passing_score;
            $maxScore = $this->has('max_score')
                ? $this->input('max_score')
                : $test->max_score;

            if ($passingScore !== null && (float) $passingScore > (float) $maxScore) {
                $validator->errors()->add('passing_score', 'The passing score field must be less than or equal to max score.');
            }
        });
    }
}

<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreTestCatalogRequest extends FormRequest
{
    use AuthorizesTestCatalog;

    public function authorize(): bool
    {
        return $this->canManageTestCatalog();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'max_score' => ['required', 'numeric', 'min:1'],
            'passing_score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('passing_score') || ! $this->filled('max_score')) {
                return;
            }

            if ((float) $this->input('passing_score') > (float) $this->input('max_score')) {
                $validator->errors()->add('passing_score', 'The passing score field must be less than or equal to max score.');
            }
        });
    }
}

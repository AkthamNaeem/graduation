<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Models\Test;

class UpdateTestCatalogRequest extends StoreTestCatalogRequest
{
    public function authorize(): bool
    {
        $test = $this->route('test');

        return $test instanceof Test && $this->canUpdateTest($test);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            'max_score' => ['prohibited'],
            'passing_score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

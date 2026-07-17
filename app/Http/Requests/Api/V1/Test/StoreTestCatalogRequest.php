<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Enums\UserRole;
use App\Http\Requests\Api\V1\Test\Concerns\AuthorizesTestCatalog;
use App\Models\Test;
use Illuminate\Foundation\Http\FormRequest;

class StoreTestCatalogRequest extends FormRequest
{
    use AuthorizesTestCatalog;

    public function authorize(): bool
    {
        return $this->authenticatedUser()?->can('create', Test::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => $this->authenticatedUser()?->role === UserRole::ADMIN
                ? ['required', 'integer', 'exists:companies,id']
                : ['prohibited'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'max_score' => ['prohibited'],
            'passing_score' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['max_score.prohibited' => 'The maximum score is calculated from the test questions.'];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminSkillRequest extends FormRequest
{
    use AuthorizesAdmin;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            'sort_by' => ['sometimes', 'string', Rule::in(['id', 'name', 'slug', 'created_at'])],
            'sort_direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}

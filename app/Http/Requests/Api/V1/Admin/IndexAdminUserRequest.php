<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdminUserRequest extends FormRequest
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
            'role' => ['sometimes', 'string', Rule::in(['admin', 'job_seeker', 'employer'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])],
            'created_from' => ['sometimes', 'date'],
            'created_to' => ['sometimes', 'date', 'after_or_equal:created_from'],
            'sort_by' => ['sometimes', 'string', Rule::in(['id', 'name', 'email', 'role', 'status', 'created_at'])],
            'sort_direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}

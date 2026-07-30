<?php

namespace App\Http\Requests\Api\V1\Company;

use App\Enums\CompanyInvitationStatus;
use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCompanyInvitationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(CompanyInvitationStatus::class)],
            'company_role' => ['sometimes', Rule::enum(CompanyRole::class)],
            'expires_before' => ['sometimes', 'date'],
            'expires_after' => ['sometimes', 'date'],
            'sort_by' => ['sometimes', Rule::in(['created_at', 'expires_at', 'email'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

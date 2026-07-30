<?php

namespace App\Http\Requests\Api\V1\Company;

use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCompanyMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'company_role' => ['sometimes', Rule::enum(CompanyRole::class)],
            'membership_status' => ['sometimes', Rule::enum(CompanyMembershipStatus::class)],
            'sort_by' => ['sometimes', Rule::in(['name', 'email', 'joined_at', 'created_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

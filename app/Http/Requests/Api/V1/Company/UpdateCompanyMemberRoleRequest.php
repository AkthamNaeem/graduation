<?php

namespace App\Http\Requests\Api\V1\Company;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') !== null;
    }

    public function rules(): array
    {
        return [
            'company_role' => ['required', Rule::enum(CompanyRole::class)],
        ];
    }
}

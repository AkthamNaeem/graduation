<?php

namespace App\Http\Requests\Api\V1\Company;

use App\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferCompanyOwnershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') !== null;
    }

    public function rules(): array
    {
        return [
            'new_owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'current_owner_user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'previous_owner_role' => [
                'sometimes',
                Rule::in([
                    CompanyRole::COMPANY_ADMIN->value,
                    CompanyRole::RECRUITER->value,
                    CompanyRole::INTERVIEWER->value,
                    CompanyRole::REVIEWER->value,
                ]),
            ],
        ];
    }
}

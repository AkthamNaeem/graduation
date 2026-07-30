<?php

namespace App\Http\Requests\Api\V1\Company;

use App\Enums\CompanyMembershipStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') !== null;
    }

    public function rules(): array
    {
        return [
            'membership_status' => [
                'required',
                Rule::in([
                    CompanyMembershipStatus::ACTIVE->value,
                    CompanyMembershipStatus::SUSPENDED->value,
                ]),
            ],
        ];
    }
}

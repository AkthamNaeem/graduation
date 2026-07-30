<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\CompanyApprovalStatus;
use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminUpdateCompanyRequest extends FormRequest
{
    use AuthorizesAdmin;

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'approval_status' => ['sometimes', Rule::enum(CompanyApprovalStatus::class)],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\CompanyApprovalStatus;
use App\Http\Requests\Api\V1\Admin\Concerns\AuthorizesAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminStoreCompanyRequest extends FormRequest
{
    use AuthorizesAdmin;

    protected function prepareForValidation(): void
    {
        if ($this->has('owner.email')) {
            $owner = (array) $this->input('owner');
            $owner['email'] = mb_strtolower(trim((string) ($owner['email'] ?? '')));
            $this->merge(['owner' => $owner]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'approval_status' => ['sometimes', Rule::enum(CompanyApprovalStatus::class)],
            'owner' => ['sometimes', 'array:name,email'],
            'owner.name' => ['sometimes', 'string', 'max:255'],
            'owner.email' => ['required_with:owner', 'email', 'max:255'],
        ];
    }
}

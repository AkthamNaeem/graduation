<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Enums\CompanyPermission;
use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use App\Services\CompanyPermissionService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    use AuthorizesProfileRoles;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && app(CompanyPermissionService::class)->can(
                $user,
                CompanyPermission::UPDATE_COMPANY,
                $user->employerProfile?->company_id,
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

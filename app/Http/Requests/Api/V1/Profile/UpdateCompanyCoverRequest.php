<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Enums\CompanyPermission;
use App\Services\CompanyPermissionService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyCoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('sanctum');

        return $user !== null && app(CompanyPermissionService::class)->can(
            $user,
            CompanyPermission::UPDATE_COMPANY,
            $user->employerProfile?->company_id,
        );
    }

    public function rules(): array
    {
        return [
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}

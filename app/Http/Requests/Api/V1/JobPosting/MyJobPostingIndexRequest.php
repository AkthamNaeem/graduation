<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Enums\UserRole;
use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;

class MyJobPostingIndexRequest extends IndexJobPostingRequest
{
    use ResolvesJobPostingUser;

    public function authorize(): bool
    {
        return $this->isEmployerUser();
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'company_id' => $this->authenticatedUser()?->role === UserRole::ADMIN
                ? ['required', 'integer', 'exists:companies,id']
                : ['prohibited'],
        ];
    }
}

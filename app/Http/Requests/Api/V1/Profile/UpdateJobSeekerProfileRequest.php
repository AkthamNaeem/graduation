<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use App\Http\Requests\Concerns\ReturnsCityValidationCodes;
use App\Rules\ActiveSyrianCity;
use Illuminate\Foundation\Http\FormRequest;

class UpdateJobSeekerProfileRequest extends FormRequest
{
    use AuthorizesProfileRoles;
    use ReturnsCityValidationCodes;

    public function authorize(): bool
    {
        return $this->isJobSeeker();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'filled', 'string', 'max:255'],
            'headline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city_id' => ['bail', 'sometimes', 'nullable', 'integer', new ActiveSyrianCity],
            'portfolio_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'linkedin_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'github_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ];
    }
}

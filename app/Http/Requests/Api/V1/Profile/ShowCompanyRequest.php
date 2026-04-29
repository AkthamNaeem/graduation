<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use Illuminate\Foundation\Http\FormRequest;

class ShowCompanyRequest extends FormRequest
{
    use AuthorizesProfileRoles;

    public function authorize(): bool
    {
        return $this->isEmployer();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

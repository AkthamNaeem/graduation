<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\Concerns\NormalizesEmail;
use App\Http\Requests\Concerns\ReturnsCityValidationCodes;
use App\Rules\ActiveSyrianCity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class JobSeekerRegisterRequest extends FormRequest
{
    use NormalizesEmail, ReturnsCityValidationCodes;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'terms_accepted' => ['required', 'accepted'],
            'phone' => ['nullable', 'string', 'max:30'],
            'location' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer', new ActiveSyrianCity],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}

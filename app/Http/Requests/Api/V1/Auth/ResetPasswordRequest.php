<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Api\V1\Auth\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    use NormalizesEmail;

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
            'email' => ['required', 'string', 'email'],
            'otp' => [
                'required',
                'string',
                'size:'.max(1, (int) config('otp.length')),
                'regex:/^\d+$/',
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') !== null;
    }

    public function rules(): array
    {
        return [
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}

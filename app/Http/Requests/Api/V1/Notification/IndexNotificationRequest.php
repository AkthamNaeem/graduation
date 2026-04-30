<?php

namespace App\Http\Requests\Api\V1\Notification;

use Illuminate\Foundation\Http\FormRequest;

class IndexNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('sanctum') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

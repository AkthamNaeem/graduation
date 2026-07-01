<?php

namespace App\Http\Requests\Api\V1\Notification;

use Illuminate\Foundation\Http\FormRequest;

class IndexNotificationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_read')) {
            return;
        }

        $value = $this->query('is_read');

        if ($value === 'true' || $value === 'false') {
            $this->merge([
                'is_read' => $value === 'true',
            ]);
        }
    }

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
            'is_read' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }
}

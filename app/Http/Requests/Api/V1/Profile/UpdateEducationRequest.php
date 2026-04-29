<?php

namespace App\Http\Requests\Api\V1\Profile;

class UpdateEducationRequest extends StoreEducationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'institution' => ['sometimes', 'required', 'string', 'max:255'],
            'degree' => ['sometimes', 'nullable', 'string', 'max:255'],
            'field_of_study' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

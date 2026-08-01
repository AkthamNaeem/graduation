<?php

namespace App\Http\Requests\Api\V1\Reference;

use Illuminate\Foundation\Http\FormRequest;

class IndexCityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('active_only')) {
            return;
        }

        $activeOnly = filter_var(
            $this->input('active_only'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );

        if ($activeOnly !== null) {
            $this->merge(['active_only' => $activeOnly]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'active_only' => ['sometimes', 'boolean'],
        ];
    }
}

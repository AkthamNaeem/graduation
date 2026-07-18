<?php

namespace App\Http\Requests\Api\V1\CV;

use App\Http\Requests\Api\V1\Profile\Concerns\AuthorizesProfileRoles;
use Illuminate\Foundation\Http\FormRequest;

class CVIndexRequest extends FormRequest
{
    use AuthorizesProfileRoles;

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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'include_archived' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'in:uploaded,processing,parsed,failed'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('include_archived'))) {
            $value = filter_var($this->input('include_archived'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value !== null) {
                $this->merge(['include_archived' => $value]);
            }
        }
    }
}

<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationTestAssignment;
use Illuminate\Foundation\Http\FormRequest;

class GrantTestRetakeRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $assignment = $this->route('applicationTestAssignment');

        return $assignment instanceof ApplicationTestAssignment
            && ($this->authenticatedUser()?->can('manageRetakes', $assignment) ?? false);
    }

    public function rules(): array
    {
        return [
            'deadline_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'instructions'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}

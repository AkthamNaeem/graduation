<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationTestAssignment;
use Illuminate\Foundation\Http\FormRequest;

class ExtendTestAssignmentDeadlineRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $assignment = $this->route('applicationTestAssignment');

        return $assignment instanceof ApplicationTestAssignment
            && ($this->authenticatedUser()?->can('extendDeadline', $assignment) ?? false);
    }

    public function rules(): array
    {
        return [
            'deadline_at' => ['required', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason') && is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}

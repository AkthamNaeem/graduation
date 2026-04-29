<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationTestAssignment;
use Illuminate\Foundation\Http\FormRequest;

class SubmitTestAttemptRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $assignment = $this->route('applicationTestAssignment');

        return $assignment instanceof ApplicationTestAssignment
            && ($this->authenticatedUser()?->can('submit', $assignment) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;

class CancelInterviewRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $interview = $this->route('interview');

        return $interview instanceof Interview && ($this->authenticatedUser()?->can('cancel', $interview) ?? false);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
            'candidate_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

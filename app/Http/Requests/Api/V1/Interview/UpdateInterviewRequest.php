<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInterviewRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $interview = $this->route('interview');

        return $interview instanceof Interview
            && ($this->authenticatedUser()?->can('update', $interview) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'interview_type' => ['required', 'string'],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'interview_mode' => ['required', 'string', Rule::in(['video', 'phone', 'in_person'])],
            'location' => ['sometimes', 'nullable', 'string'],
            'meeting_link' => ['sometimes', 'nullable', 'url'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

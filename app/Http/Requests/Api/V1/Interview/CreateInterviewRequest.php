<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInterviewRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $jobApplication = $this->route('jobApplication');
        $user = $this->authenticatedUser();

        return $jobApplication instanceof JobApplication
            && ($user?->can('createForApplication', [Interview::class, $jobApplication]) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'interview_type' => ['required', 'string'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'interview_mode' => ['required', 'string', Rule::in(['video', 'phone', 'in_person'])],
            'location' => ['sometimes', 'nullable', 'string'],
            'meeting_link' => ['sometimes', 'nullable', 'url'],
            'note' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Enums\InterviewMode;
use App\Enums\InterviewType;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Http\Requests\Api\V1\Interview\Concerns\NormalizesInterviewScheduleInput;
use App\Models\Interview;
use App\Models\JobApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInterviewRequest extends FormRequest
{
    use NormalizesInterviewScheduleInput;
    use ResolvesApplicationUser;

    protected function prepareForValidation(): void
    {
        $this->normalizeScheduleInput();
    }

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
            'type' => ['required', 'string', Rule::enum(InterviewType::class)],
            'mode' => ['required', 'string', Rule::enum(InterviewMode::class)],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'between:1,480'],
            'location_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'meeting_link' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'video_provider' => ['sometimes', 'nullable', 'string', Rule::in(['livekit'])],
            'candidate_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Enums\InterviewMode;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Http\Requests\Api\V1\Interview\Concerns\NormalizesInterviewScheduleInput;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleInterviewRequest extends FormRequest
{
    use NormalizesInterviewScheduleInput;
    use ResolvesApplicationUser;

    protected function prepareForValidation(): void
    {
        $this->normalizeScheduleInput();
    }

    public function authorize(): bool
    {
        $interview = $this->route('interview');

        return $interview instanceof Interview && ($this->authenticatedUser()?->can('reschedule', $interview) ?? false);
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(InterviewMode::class)],
            'scheduled_start_at' => ['required', 'date'],
            'scheduled_end_at' => ['required', 'date'],
            'location_text' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'meeting_link' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}

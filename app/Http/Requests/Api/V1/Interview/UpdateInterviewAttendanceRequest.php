<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Enums\InterviewAttendanceStatus;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInterviewAttendanceRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $interview = $this->route('interview');

        return $interview instanceof Interview && ($this->authenticatedUser()?->can('manageAttendance', $interview) ?? false);
    }

    public function rules(): array
    {
        $allowed = [InterviewAttendanceStatus::PRESENT->value, InterviewAttendanceStatus::ABSENT->value, InterviewAttendanceStatus::EXCUSED->value];

        return [
            'candidate_status' => ['required', Rule::in($allowed)],
            'interviewer_status' => ['required', Rule::in($allowed)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

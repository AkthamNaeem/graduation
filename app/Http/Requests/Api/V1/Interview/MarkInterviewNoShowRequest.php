<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkInterviewNoShowRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $interview = $this->route('interview');

        return $interview instanceof Interview && ($this->authenticatedUser()?->can('markNoShow', $interview) ?? false);
    }

    public function rules(): array
    {
        return [
            'party' => ['required', Rule::in(['candidate', 'interviewer', 'both'])],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Interview;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\Interview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluateInterviewRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $interview = $this->route('interview');

        return $interview instanceof Interview
            && ($this->authenticatedUser()?->can('evaluate', $interview) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recommendation' => ['required', 'string', Rule::in(['advance', 'hold', 'reject'])],
            'overall_comment' => ['sometimes', 'nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.criterion' => ['required', 'string'],
            'items.*.score' => ['required', 'integer', 'between:1,5'],
            'items.*.comment' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\TestAttempt;
use Illuminate\Foundation\Http\FormRequest;

class EvaluateTestAttemptRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $attempt = $this->route('testAttempt');

        return $attempt instanceof TestAttempt
            && ($this->authenticatedUser()?->can('evaluate', $attempt) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $attempt = $this->route('testAttempt');
        $maxScore = $attempt instanceof TestAttempt
            ? (float) $attempt->applicationTestAssignment->test->max_score
            : null;

        $scoreRules = ['required', 'numeric', 'min:0'];

        if ($maxScore !== null) {
            $scoreRules[] = 'max:'.$maxScore;
        }

        return [
            'score' => $scoreRules,
            'feedback' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

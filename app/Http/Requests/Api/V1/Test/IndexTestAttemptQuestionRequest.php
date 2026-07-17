<?php

namespace App\Http\Requests\Api\V1\Test;

use App\Enums\UserRole;
use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\TestAttempt;
use Illuminate\Foundation\Http\FormRequest;

class IndexTestAttemptQuestionRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $attempt = $this->route('testAttempt');
        $user = $this->authenticatedUser();

        return $attempt instanceof TestAttempt
            && $user?->role === UserRole::JOB_SEEKER
            && $user->can('viewQuestions', $attempt);
    }

    public function rules(): array
    {
        return [];
    }
}

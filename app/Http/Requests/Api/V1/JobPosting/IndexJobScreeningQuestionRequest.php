<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;

class IndexJobScreeningQuestionRequest extends FormRequest
{
    use ResolvesJobPostingUser;

    public function authorize(): bool
    {
        $jobPosting = $this->route('jobPosting');
        if (! $jobPosting instanceof JobPosting) {
            return false;
        }

        if ($jobPosting->status === 'open') {
            return $jobPosting->company()->where('approval_status', 'approved')->exists();
        }

        return $this->authenticatedUser()?->can('view', $jobPosting) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

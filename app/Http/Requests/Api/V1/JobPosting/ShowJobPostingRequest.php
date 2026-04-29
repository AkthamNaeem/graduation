<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;

class ShowJobPostingRequest extends FormRequest
{
    use ResolvesJobPostingUser;

    public function authorize(): bool
    {
        $jobPosting = $this->route('jobPosting');

        if (! $jobPosting instanceof JobPosting) {
            return false;
        }

        if ($jobPosting->status === 'open') {
            return true;
        }

        $user = $this->authenticatedUser();

        return $user?->can('view', $jobPosting) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

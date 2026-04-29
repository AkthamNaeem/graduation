<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;

class PublishJobPostingRequest extends FormRequest
{
    use ResolvesJobPostingUser;

    public function authorize(): bool
    {
        $jobPosting = $this->route('jobPosting');

        return $jobPosting instanceof JobPosting
            && $this->isEmployerUser()
            && $this->authenticatedUser()?->can('publish', $jobPosting);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

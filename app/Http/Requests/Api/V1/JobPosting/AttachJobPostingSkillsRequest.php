<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;

class AttachJobPostingSkillsRequest extends FormRequest
{
    use ResolvesJobPostingUser;

    public function authorize(): bool
    {
        $jobPosting = $this->route('jobPosting');

        return $jobPosting instanceof JobPosting
            && $this->isEmployerUser()
            && $this->authenticatedUser()?->can('attachSkills', $jobPosting);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'skill_ids' => ['required', 'array', 'min:1'],
            'skill_ids.*' => ['integer', 'exists:skills,id'],
        ];
    }
}

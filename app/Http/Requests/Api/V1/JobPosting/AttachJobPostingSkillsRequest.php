<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Enums\JobSkillRequirementType;
use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'skill_ids' => ['required_without:skills', 'prohibits:skills', 'array', 'min:1'],
            'skill_ids.*' => ['integer', 'distinct', 'exists:skills,id'],
            'skills' => ['required_without:skill_ids', 'prohibits:skill_ids', 'array', 'min:1'],
            'skills.*.skill_id' => ['required', 'integer', 'distinct', 'exists:skills,id'],
            'skills.*.requirement_type' => ['required', Rule::enum(JobSkillRequirementType::class)],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\JobPosting;

use App\Http\Requests\Api\V1\JobPosting\Concerns\NormalizesJobSkillInput;
use App\Http\Requests\Api\V1\JobPosting\Concerns\ResolvesJobPostingUser;
use App\Models\JobPosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttachJobPostingSkillsRequest extends FormRequest
{
    use NormalizesJobSkillInput;
    use ResolvesJobPostingUser;

    protected function prepareForValidation(): void
    {
        $this->normalizeJobSkillInput();
    }

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
        return $this->jobSkillRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateJobSkillContracts($validator, true));
    }
}

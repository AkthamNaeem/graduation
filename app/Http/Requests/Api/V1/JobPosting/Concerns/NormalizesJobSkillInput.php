<?php

namespace App\Http\Requests\Api\V1\JobPosting\Concerns;

use App\Enums\JobSkillRequirementType;
use Illuminate\Validation\Validator;

trait NormalizesJobSkillInput
{
    protected function normalizeJobSkillInput(): void
    {
        if (! is_array($this->input('skills'))) {
            return;
        }

        $this->merge([
            'skills' => collect($this->input('skills'))->map(function ($skill): mixed {
                if (! is_array($skill) || ! isset($skill['requirement_type'])) {
                    return $skill;
                }

                $normalized = JobSkillRequirementType::normalize((string) $skill['requirement_type']);
                if ($normalized !== null) {
                    $skill['requirement_type'] = $normalized->value;
                }

                return $skill;
            })->all(),
        ]);
    }

    protected function validateJobSkillContracts(Validator $validator, bool $requireAContract = false): void
    {
        $families = collect([
            $this->exists('skill_ids'),
            $this->exists('skills'),
            $this->exists('required_skills') || $this->exists('nice_to_have_skills'),
        ])->filter();

        if ($families->count() > 1) {
            $validator->errors()->add('skills', 'Legacy and separated skill contracts cannot be combined in one request.');
        }

        if ($requireAContract && $families->isEmpty()) {
            $validator->errors()->add('skills', 'At least one skill contract is required.');
        }

        $requiredIds = collect($this->input('required_skills', []))->pluck('skill_id')->filter();
        $niceIds = collect($this->input('nice_to_have_skills', []))->pluck('skill_id')->filter();
        if ($requiredIds->intersect($niceIds)->isNotEmpty()) {
            $validator->errors()->add(
                'nice_to_have_skills',
                'A skill cannot be both required and nice-to-have in the same request.',
            );
        }
    }

    /** @return array<string, mixed> */
    protected function jobSkillRules(): array
    {
        return [
            'skill_ids' => ['sometimes', 'array', 'max:100'],
            'skill_ids.*' => ['integer', 'distinct', 'exists:skills,id'],
            'skills' => ['sometimes', 'array', 'max:100'],
            'skills.*.skill_id' => ['required', 'integer', 'distinct', 'exists:skills,id'],
            'skills.*.requirement_type' => ['required', 'string', 'in:required,nice_to_have'],
            'skills.*.weight' => ['sometimes', 'integer', 'between:1,5'],
            'required_skills' => ['sometimes', 'array', 'max:100'],
            'required_skills.*.skill_id' => ['required', 'integer', 'distinct', 'exists:skills,id'],
            'required_skills.*.weight' => ['required', 'integer', 'between:1,5'],
            'nice_to_have_skills' => ['sometimes', 'array', 'max:100'],
            'nice_to_have_skills.*.skill_id' => ['required', 'integer', 'distinct', 'exists:skills,id'],
            'nice_to_have_skills.*.weight' => ['sometimes', 'integer', 'between:1,5'],
        ];
    }
}

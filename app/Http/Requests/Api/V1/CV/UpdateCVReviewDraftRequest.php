<?php

namespace App\Http\Requests\Api\V1\CV;

use App\Http\Requests\Concerns\ReturnsCityValidationCodes;
use App\Rules\ActiveSyrianCity;
use Illuminate\Validation\Validator;

class UpdateCVReviewDraftRequest extends CVIndexRequest
{
    use ReturnsCityValidationCodes;

    public function rules(): array
    {
        return [
            'profile' => ['required', 'array:headline,phone,summary,location,city_id,portfolio_url,linkedin_url,github_url'],
            'profile.headline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.phone' => ['present', 'nullable', 'string', 'max:50'],
            'profile.summary' => ['present', 'nullable', 'string', 'max:5000'],
            'profile.location' => ['present', 'nullable', 'string', 'max:255'],
            'profile.city_id' => ['bail', 'sometimes', 'nullable', 'integer', new ActiveSyrianCity],
            'profile.portfolio_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'profile.linkedin_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'profile.github_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'experience' => ['present', 'array', 'max:100'],
            'experience.*' => ['array:id,title,company_name,location,start_date,end_date,is_current,description'],
            'experience.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'experience.*.title' => ['required', 'string', 'max:255'],
            'experience.*.company_name' => ['required', 'string', 'max:255'],
            'experience.*.location' => ['present', 'nullable', 'string', 'max:255'],
            'experience.*.start_date' => ['present', 'nullable', 'date'],
            'experience.*.end_date' => ['present', 'nullable', 'date'],
            'experience.*.is_current' => ['required', 'boolean'],
            'experience.*.description' => ['present', 'nullable', 'string', 'max:10000'],
            'education' => ['present', 'array', 'max:100'],
            'education.*' => ['array:id,institution,degree,field_of_study,start_date,end_date,description'],
            'education.*.id' => ['sometimes', 'integer', 'min:1', 'distinct'],
            'education.*.institution' => ['required', 'string', 'max:255'],
            'education.*.degree' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.field_of_study' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.start_date' => ['present', 'nullable', 'date'],
            'education.*.end_date' => ['present', 'nullable', 'date'],
            'education.*.description' => ['present', 'nullable', 'string', 'max:10000'],
            'skills' => ['present', 'array', 'max:100'],
            'skills.*' => ['required', 'string', 'max:150', 'distinct:ignore_case'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->all()), ['profile', 'experience', 'education', 'skills']);
            if ($unexpected !== []) {
                $validator->errors()->add('payload', __('validation.custom_messages.unexpected_fields'));
            }

            foreach ($this->input('experience', []) as $index => $item) {
                if (($item['is_current'] ?? false) && ($item['end_date'] ?? null) !== null) {
                    $validator->errors()->add("experience.{$index}.end_date", __('validation.custom_messages.current_end_date'));
                }
                $this->validateDateOrder($validator, "experience.{$index}", $item);
            }
            foreach ($this->input('education', []) as $index => $item) {
                $this->validateDateOrder($validator, "education.{$index}", $item);
            }

            $this->validateDuplicateEntries($validator, 'experience', ['title', 'company_name', 'start_date']);
            $this->validateDuplicateEntries($validator, 'education', ['institution', 'degree', 'start_date']);

        }];
    }

    /** @param list<string> $identityFields */
    private function validateDuplicateEntries(Validator $validator, string $section, array $identityFields): void
    {
        $seen = [];
        foreach ($this->input($section, []) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $identity = collect($identityFields)
                ->map(fn (string $field): string => mb_strtolower(trim((string) ($item[$field] ?? ''))))
                ->implode('|');
            if ($identity === str_repeat('|', count($identityFields) - 1)) {
                continue;
            }
            if (isset($seen[$identity])) {
                $validator->errors()->add("{$section}.{$index}", __('validation.custom_messages.duplicate_draft_item'));
            }
            $seen[$identity] = true;
        }
    }

    private function validateDateOrder(Validator $validator, string $path, mixed $item): void
    {
        if (! is_array($item) || empty($item['start_date']) || empty($item['end_date'])) {
            return;
        }
        if (strtotime($item['end_date']) < strtotime($item['start_date'])) {
            $validator->errors()->add("{$path}.end_date", __('validation.custom_messages.end_date_order'));
        }
    }
}

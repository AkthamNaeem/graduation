<?php

namespace App\Services\CV;

use App\Rules\ActiveSyrianCity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CVReviewDraftValidator
{
    /** @param array<string, mixed> $draft @throws ValidationException */
    public function validate(array $draft): void
    {
        $validator = Validator::make($draft, [
            'profile' => ['required', 'array:headline,phone,summary,location,city_id,portfolio_url,linkedin_url,github_url'],
            'profile.headline' => ['present', 'nullable', 'string', 'max:255'],
            'profile.phone' => ['present', 'nullable', 'string', 'max:50'],
            'profile.summary' => ['present', 'nullable', 'string', 'max:5000'],
            'profile.location' => ['present', 'nullable', 'string', 'max:255'],
            'profile.city_id' => ['bail', 'sometimes', 'nullable', 'integer', new ActiveSyrianCity],
            'profile.portfolio_url' => ['present', 'nullable', 'url:http,https', 'max:2048'],
            'profile.linkedin_url' => ['present', 'nullable', 'url:http,https', 'max:2048'],
            'profile.github_url' => ['present', 'nullable', 'url:http,https', 'max:2048'],
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
        ]);

        $validator->after(function ($validator) use ($draft): void {
            $unexpected = array_diff(array_keys($draft), ['profile', 'experience', 'education', 'skills']);
            if ($unexpected !== []) {
                $validator->errors()->add('payload', __('validation.custom_messages.unexpected_fields'));
            }
            foreach (['experience', 'education'] as $section) {
                foreach ($draft[$section] ?? [] as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (! empty($item['start_date']) && ! empty($item['end_date'])
                        && strtotime($item['end_date']) < strtotime($item['start_date'])) {
                        $validator->errors()->add("{$section}.{$index}.end_date", __('validation.custom_messages.end_date_order'));
                    }
                    if ($section === 'experience' && ($item['is_current'] ?? false) && ($item['end_date'] ?? null) !== null) {
                        $validator->errors()->add("{$section}.{$index}.end_date", __('validation.custom_messages.current_end_date'));
                    }
                }
            }
        });

        $validator->validate();
    }
}

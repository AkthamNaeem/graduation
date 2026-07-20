<?php

namespace App\Http\Requests\Api\V1\CV;

use Illuminate\Validation\Validator;

class UpdateCVReviewDraftRequest extends CVIndexRequest
{
    public function rules(): array
    {
        return [
            'profile' => ['required', 'array:phone,summary,location'],
            'profile.phone' => ['present', 'nullable', 'string', 'max:50'],
            'profile.summary' => ['present', 'nullable', 'string', 'max:5000'],
            'profile.location' => ['present', 'nullable', 'string', 'max:255'],
            'experience' => ['present', 'array', 'max:100'],
            'experience.*' => ['array:title,company_name,location,start_date,end_date,is_current,description'],
            'experience.*.title' => ['required', 'string', 'max:255'],
            'experience.*.company_name' => ['required', 'string', 'max:255'],
            'experience.*.location' => ['present', 'nullable', 'string', 'max:255'],
            'experience.*.start_date' => ['present', 'nullable', 'date'],
            'experience.*.end_date' => ['present', 'nullable', 'date'],
            'experience.*.is_current' => ['required', 'boolean'],
            'experience.*.description' => ['present', 'nullable', 'string', 'max:10000'],
            'education' => ['present', 'array', 'max:100'],
            'education.*' => ['array:institution,degree,field_of_study,start_date,end_date,description'],
            'education.*.institution' => ['required', 'string', 'max:255'],
            'education.*.degree' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.field_of_study' => ['present', 'nullable', 'string', 'max:255'],
            'education.*.start_date' => ['present', 'nullable', 'date'],
            'education.*.end_date' => ['present', 'nullable', 'date'],
            'education.*.description' => ['present', 'nullable', 'string', 'max:10000'],
            'skills' => ['present', 'array', 'max:100'],
            'skills.*' => ['required', 'string', 'max:150'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $unexpected = array_diff(array_keys($this->all()), ['profile', 'experience', 'education', 'skills']);
            if ($unexpected !== []) {
                $validator->errors()->add('payload', 'The payload contains unexpected fields.');
            }

            foreach ($this->input('experience', []) as $index => $item) {
                if (($item['is_current'] ?? false) && ($item['end_date'] ?? null) !== null) {
                    $validator->errors()->add("experience.{$index}.end_date", 'The end date must be null for a current experience.');
                }
                $this->validateDateOrder($validator, "experience.{$index}", $item);
            }
            foreach ($this->input('education', []) as $index => $item) {
                $this->validateDateOrder($validator, "education.{$index}", $item);
            }

        }];
    }

    private function validateDateOrder(Validator $validator, string $path, mixed $item): void
    {
        if (! is_array($item) || empty($item['start_date']) || empty($item['end_date'])) {
            return;
        }
        if (strtotime($item['end_date']) < strtotime($item['start_date'])) {
            $validator->errors()->add("{$path}.end_date", 'The end date must be after or equal to the start date.');
        }
    }
}

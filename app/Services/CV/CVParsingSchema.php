<?php

namespace App\Services\CV;

use Illuminate\Support\Facades\Validator;

class CVParsingSchema
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['full_name', 'email', 'phone', 'location', 'birth_date', 'nationality', 'marital_status', 'summary', 'experience', 'education', 'certifications', 'skills', 'languages'],
            'properties' => [
                'full_name' => $nullableString,
                'email' => $nullableString,
                'phone' => $nullableString,
                'location' => $nullableString,
                'birth_date' => [
                    'type' => ['string', 'null'],
                    'description' => 'Complete birth date in YYYY-MM-DD format, or null when incomplete.',
                ],
                'nationality' => $nullableString,
                'marital_status' => $nullableString,
                'summary' => $nullableString,
                'experience' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'company_name', 'location', 'work_mode', 'start_date', 'end_date', 'is_current', 'description', 'responsibilities', 'evidence', 'confidence_score'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'company_name' => ['type' => 'string'],
                            'location' => $nullableString,
                            'work_mode' => ['type' => ['string', 'null'], 'enum' => ['remote', 'onsite', 'hybrid', null]],
                            'start_date' => $nullableString,
                            'end_date' => $nullableString,
                            'is_current' => ['type' => 'boolean'],
                            'description' => $nullableString,
                            'responsibilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'evidence' => ['type' => 'string'],
                            'confidence_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
                'education' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['degree', 'field_of_study', 'institution', 'start_year', 'graduation_year', 'is_expected', 'description', 'evidence', 'confidence_score'],
                        'properties' => [
                            'degree' => $nullableString,
                            'field_of_study' => $nullableString,
                            'institution' => ['type' => 'string'],
                            'start_year' => ['type' => ['integer', 'null']],
                            'graduation_year' => ['type' => ['integer', 'null']],
                            'is_expected' => ['type' => 'boolean'],
                            'description' => $nullableString,
                            'evidence' => ['type' => 'string'],
                            'confidence_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
                'certifications' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'issuer', 'issue_year', 'expiration_year', 'description', 'evidence', 'confidence_score'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'issuer' => $nullableString,
                            'issue_year' => ['type' => ['integer', 'null']],
                            'expiration_year' => ['type' => ['integer', 'null']],
                            'description' => $nullableString,
                            'evidence' => ['type' => 'string'],
                            'confidence_score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
                'skills' => ['type' => 'array', 'items' => ['type' => 'string']],
                'languages' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'level'],
                        'properties' => ['name' => ['type' => 'string'], 'level' => $nullableString],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $parsed */
    public function matches(array $parsed): bool
    {
        if (array_diff(array_keys($parsed), ['full_name', 'email', 'phone', 'location', 'birth_date', 'nationality', 'marital_status', 'summary', 'experience', 'education', 'certifications', 'skills', 'languages']) !== []) {
            return false;
        }

        $validator = Validator::make($parsed, [
            'full_name' => ['present', 'nullable', 'string'],
            'email' => ['present', 'nullable', 'string'],
            'phone' => ['present', 'nullable', 'string'],
            'location' => ['present', 'nullable', 'string'],
            'birth_date' => ['present', 'nullable', 'string'],
            'nationality' => ['present', 'nullable', 'string'],
            'marital_status' => ['present', 'nullable', 'string'],
            'summary' => ['present', 'nullable', 'string'],
            'experience' => ['present', 'array'],
            'experience.*' => ['array:title,company_name,location,work_mode,start_date,end_date,is_current,description,responsibilities,evidence,confidence_score'],
            'experience.*.title' => ['required', 'string'],
            'experience.*.company_name' => ['required', 'string'],
            'experience.*.location' => ['present', 'nullable', 'string'],
            'experience.*.work_mode' => ['present', 'nullable', 'in:remote,onsite,hybrid'],
            'experience.*.start_date' => ['present', 'nullable', 'regex:/^\d{4}(?:-(?:0[1-9]|1[0-2]))?$/'],
            'experience.*.end_date' => ['present', 'nullable', 'regex:/^\d{4}(?:-(?:0[1-9]|1[0-2]))?$/'],
            'experience.*.is_current' => ['required', 'boolean'],
            'experience.*.description' => ['present', 'nullable', 'string'],
            'experience.*.responsibilities' => ['present', 'array'],
            'experience.*.responsibilities.*' => ['string'],
            'experience.*.evidence' => ['required', 'string'],
            'experience.*.confidence_score' => ['required', 'numeric', 'between:0,1'],
            'education' => ['present', 'array'],
            'education.*' => ['array:degree,field_of_study,institution,start_year,graduation_year,is_expected,description,evidence,confidence_score'],
            'education.*.degree' => ['present', 'nullable', 'string'],
            'education.*.field_of_study' => ['present', 'nullable', 'string'],
            'education.*.institution' => ['required', 'string'],
            'education.*.start_year' => ['present', 'nullable', 'integer'],
            'education.*.graduation_year' => ['present', 'nullable', 'integer'],
            'education.*.is_expected' => ['required', 'boolean'],
            'education.*.description' => ['present', 'nullable', 'string'],
            'education.*.evidence' => ['required', 'string'],
            'education.*.confidence_score' => ['required', 'numeric', 'between:0,1'],
            'certifications' => ['present', 'array'],
            'certifications.*' => ['array:name,issuer,issue_year,expiration_year,description,evidence,confidence_score'],
            'certifications.*.name' => ['required', 'string'],
            'certifications.*.issuer' => ['present', 'nullable', 'string'],
            'certifications.*.issue_year' => ['present', 'nullable', 'integer'],
            'certifications.*.expiration_year' => ['present', 'nullable', 'integer'],
            'certifications.*.description' => ['present', 'nullable', 'string'],
            'certifications.*.evidence' => ['required', 'string'],
            'certifications.*.confidence_score' => ['required', 'numeric', 'between:0,1'],
            'skills' => ['present', 'array'],
            'skills.*' => ['string'],
            'languages' => ['present', 'array'],
            'languages.*' => ['array:name,level'],
            'languages.*.name' => ['required', 'string'],
            'languages.*.level' => ['present', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return false;
        }

        foreach ($parsed['certifications'] as $certification) {
            if ($certification['issue_year'] !== null
                && $certification['expiration_year'] !== null
                && $certification['expiration_year'] < $certification['issue_year']) {
                return false;
            }
        }

        return true;
    }
}

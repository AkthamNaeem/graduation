<?php

namespace App\Services\CVSummary;

use Illuminate\Support\Facades\Validator;

class CVSummarySchema
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['headline', 'summary', 'strengths', 'gaps', 'evidence'],
            'properties' => [
                'headline' => ['type' => 'string', 'maxLength' => 180],
                'summary' => ['type' => 'string', 'maxLength' => 900],
                'strengths' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => ['type' => 'string', 'maxLength' => 300],
                ],
                'gaps' => [
                    'type' => 'array',
                    'maxItems' => 5,
                    'items' => ['type' => 'string', 'maxLength' => 300],
                ],
                'evidence' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['statement', 'source'],
                        'properties' => [
                            'statement' => ['type' => 'string', 'maxLength' => 300],
                            'source' => ['type' => 'string', 'maxLength' => 200],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $summary */
    public function matches(array $summary): bool
    {
        if (array_diff(array_keys($summary), ['headline', 'summary', 'strengths', 'gaps', 'evidence']) !== []) {
            return false;
        }

        return ! Validator::make($summary, [
            'headline' => ['required', 'string', 'max:180'],
            'summary' => ['required', 'string', 'max:900'],
            'strengths' => ['present', 'array', 'max:5'],
            'strengths.*' => ['string', 'max:300'],
            'gaps' => ['present', 'array', 'max:5'],
            'gaps.*' => ['string', 'max:300'],
            'evidence' => ['present', 'array', 'max:8'],
            'evidence.*' => ['array:statement,source'],
            'evidence.*.statement' => ['required', 'string', 'max:300'],
            'evidence.*.source' => ['required', 'string', 'max:200'],
        ])->fails();
    }
}

<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlReasonFactor
{
    public const CODES = [
        'DOMAIN_MISMATCH',
        'DOMAIN_ALIGNMENT',
        'OPTIONAL_SKILLS_GAP',
        'OPTIONAL_SKILLS_ALIGNMENT',
        'REQUIRED_SKILLS_GAP',
        'REQUIRED_SKILLS_ALIGNMENT',
        'COMBINED_PROFILE_GAP',
        'COMBINED_PROFILE_ALIGNMENT',
        'CAREER_LEVEL_GAP',
        'CAREER_LEVEL_ALIGNMENT',
        'EXPERIENCE_GAP',
        'EXPERIENCE_ALIGNMENT',
        'EDUCATION_GAP',
        'EDUCATION_ALIGNMENT',
        'WORK_PREFERENCE_MISMATCH',
        'WORK_PREFERENCE_ALIGNMENT',
        'TEXT_MISMATCH',
        'TEXT_ALIGNMENT',
        'PROFILE_DATA_MISSING',
        'PROFILE_DATA_COMPLETENESS',
    ];

    public const FEATURE_GROUPS = [
        'domain_compatibility',
        'nice_transferable_skills',
        'required_skills',
        'interactions',
        'career_level',
        'experience',
        'education',
        'preferences',
        'text_alignment',
        'missing_indicators',
    ];

    private function __construct(
        public string $code,
        public string $featureGroup,
        public string $direction,
        public float $contribution,
        public float $strength,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(
        array $data,
        string $expectedDirection,
        ?string $requestId = null,
    ): self {
        MlDataValidator::responseKeys($data, [
            'code',
            'feature_group',
            'direction',
            'contribution',
            'strength',
        ], [
            'code',
            'feature_group',
            'direction',
            'contribution',
            'strength',
        ]);

        $code = MlDataValidator::responseString($data['code'], requestId: $requestId);
        $featureGroup = MlDataValidator::responseString(
            $data['feature_group'],
            requestId: $requestId,
        );
        $direction = MlDataValidator::responseString(
            $data['direction'],
            requestId: $requestId,
        );

        if (! in_array($code, self::CODES, true)
            || ! in_array($featureGroup, self::FEATURE_GROUPS, true)
            || $direction !== $expectedDirection) {
            MlDataValidator::contractFailure(requestId: $requestId, operation: 'rank');
        }

        return new self(
            $code,
            $featureGroup,
            $direction,
            MlDataValidator::finiteResponseNumber(
                $data['contribution'],
                requestId: $requestId,
                operation: 'rank',
            ),
            MlDataValidator::finiteResponseNumber(
                $data['strength'],
                0,
                1,
                $requestId,
                'rank',
            ),
        );
    }

    /**
     * @return array<string, float|string>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'feature_group' => $this->featureGroup,
            'direction' => $this->direction,
            'contribution' => $this->contribution,
            'strength' => $this->strength,
        ];
    }
}

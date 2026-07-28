<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlRankPrediction
{
    /**
     * @param  list<MlReasonFactor>  $topPositiveFactors
     * @param  list<MlReasonFactor>  $topNegativeFactors
     */
    private function __construct(
        public int $jobId,
        public int $rank,
        public float $rawScore,
        public float $displayScore,
        public array $topPositiveFactors,
        public array $topNegativeFactors,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $requestId = null): self
    {
        MlDataValidator::responseKeys($data, [
            'job_id',
            'rank',
            'raw_score',
            'display_score',
            'top_positive_factors',
            'top_negative_factors',
        ], [
            'job_id',
            'rank',
            'raw_score',
            'display_score',
            'top_positive_factors',
            'top_negative_factors',
        ]);

        if (! is_int($data['job_id'])
            || $data['job_id'] < 1
            || ! is_int($data['rank'])
            || $data['rank'] < 1) {
            MlDataValidator::contractFailure(requestId: $requestId, operation: 'rank');
        }

        return new self(
            jobId: $data['job_id'],
            rank: $data['rank'],
            rawScore: MlDataValidator::finiteResponseNumber(
                $data['raw_score'],
                requestId: $requestId,
                operation: 'rank',
            ),
            displayScore: MlDataValidator::finiteResponseNumber(
                $data['display_score'],
                0,
                100,
                $requestId,
                'rank',
            ),
            topPositiveFactors: self::factors(
                $data['top_positive_factors'],
                'increases_model_score',
                $requestId,
            ),
            topNegativeFactors: self::factors(
                $data['top_negative_factors'],
                'decreases_model_score',
                $requestId,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'rank' => $this->rank,
            'raw_score' => $this->rawScore,
            'display_score' => $this->displayScore,
            'top_positive_factors' => array_map(
                fn (MlReasonFactor $factor): array => $factor->toArray(),
                $this->topPositiveFactors,
            ),
            'top_negative_factors' => array_map(
                fn (MlReasonFactor $factor): array => $factor->toArray(),
                $this->topNegativeFactors,
            ),
        ];
    }

    /**
     * @return list<MlReasonFactor>
     */
    private static function factors(
        mixed $value,
        string $direction,
        ?string $requestId,
    ): array {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 3) {
            MlDataValidator::contractFailure(requestId: $requestId, operation: 'rank');
        }

        return array_map(function (mixed $factor) use ($direction, $requestId): MlReasonFactor {
            if (! is_array($factor)) {
                MlDataValidator::contractFailure(requestId: $requestId, operation: 'rank');
            }

            return MlReasonFactor::fromArray($factor, $direction, $requestId);
        }, $value);
    }
}

<?php

namespace App\Data\RecommendationMl;

use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlRankJob
{
    public function __construct(
        public int $jobId,
        public MlJobProfessionalFacts $professionalFacts,
    ) {
        if ($jobId < 1) {
            MlDataValidator::requestFailure();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        MlDataValidator::requestKeys(
            $data,
            ['job_id', 'professional_facts'],
            ['job_id', 'professional_facts'],
        );
        if (! is_int($data['job_id']) || ! is_array($data['professional_facts'])) {
            MlDataValidator::requestFailure();
        }

        return new self(
            $data['job_id'],
            MlJobProfessionalFacts::fromArray($data['professional_facts']),
        );
    }

    /**
     * @return array{job_id: int, professional_facts: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'professional_facts' => $this->professionalFacts->toArray(),
        ];
    }
}

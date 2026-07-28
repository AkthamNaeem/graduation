<?php

namespace App\Data\RecommendationMl;

use App\Exceptions\RecommendationMl\MlRecommendationValidationException;
use App\Support\RecommendationMl\MlDataValidator;

final readonly class MlRankRequest
{
    /**
     * @param  list<MlRankJob>  $jobs
     */
    public function __construct(
        public string $requestId,
        public string $featureSchemaVersion,
        public MlCandidateProfessionalFacts $candidateProfessionalFacts,
        public array $jobs,
        public int $limit,
        public ?string $profileRef = null,
    ) {
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',
            $requestId,
        ) !== 1
            || $featureSchemaVersion === ''
            || $jobs === []
            || count($jobs) > 500
            || $limit < 1
            || $limit > 100
            || $limit > count($jobs)) {
            MlDataValidator::requestFailure();
        }

        foreach ($jobs as $job) {
            if (! $job instanceof MlRankJob) {
                MlDataValidator::requestFailure();
            }
        }
        $ids = array_map(fn (MlRankJob $job): int => $job->jobId, $jobs);
        if (count($ids) !== count(array_unique($ids, SORT_REGULAR))) {
            MlDataValidator::requestFailure('ML_DUPLICATE_JOB_ID');
        }

        if ($profileRef !== null) {
            MlDataValidator::string($profileRef, 128);
            if (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/D', $profileRef) === 1
                || preg_match('/^\+?[\d\s().-]{7,}$/D', $profileRef) === 1) {
                MlDataValidator::requestFailure('ML_SENSITIVE_FIELD_NOT_ALLOWED');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        MlDataValidator::requestKeys($data, [
            'request_id',
            'feature_schema_version',
            'candidate',
            'jobs',
            'limit',
        ], [
            'request_id',
            'feature_schema_version',
            'candidate',
            'jobs',
            'limit',
        ]);

        if (! is_string($data['request_id'])
            || ! is_string($data['feature_schema_version'])
            || ! is_array($data['candidate'])
            || ! is_array($data['jobs'])
            || ! array_is_list($data['jobs'])
            || ! is_int($data['limit'])) {
            MlDataValidator::requestFailure();
        }

        MlDataValidator::requestKeys(
            $data['candidate'],
            ['profile_ref', 'professional_facts'],
            ['professional_facts'],
        );
        if (! is_array($data['candidate']['professional_facts'])) {
            MlDataValidator::requestFailure();
        }

        $jobs = array_map(function (mixed $job): MlRankJob {
            if (! is_array($job)) {
                MlDataValidator::requestFailure();
            }

            return MlRankJob::fromArray($job);
        }, $data['jobs']);

        return new self(
            requestId: $data['request_id'],
            featureSchemaVersion: $data['feature_schema_version'],
            candidateProfessionalFacts: MlCandidateProfessionalFacts::fromArray(
                $data['candidate']['professional_facts'],
            ),
            jobs: $jobs,
            limit: $data['limit'],
            profileRef: array_key_exists('profile_ref', $data['candidate'])
                ? MlDataValidator::nullableString($data['candidate']['profile_ref'], 128)
                : null,
        );
    }

    public function assertForConfiguration(MlClientConfiguration $configuration): void
    {
        if ($this->featureSchemaVersion !== $configuration->featureSchemaVersion
            || count($this->jobs) > $configuration->maxJobsPerRequest
            || $this->limit > $configuration->maxResults) {
            throw new MlRecommendationValidationException(
                internalCode: 'ML_REQUEST_CONFIGURATION_MISMATCH',
                requestId: $this->requestId,
                operation: 'rank',
            );
        }
    }

    /**
     * @return list<int>
     */
    public function jobIds(): array
    {
        return array_map(fn (MlRankJob $job): int => $job->jobId, $this->jobs);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'feature_schema_version' => $this->featureSchemaVersion,
            'candidate' => [
                'profile_ref' => $this->profileRef,
                'professional_facts' => $this->candidateProfessionalFacts->toArray(),
            ],
            'jobs' => array_map(
                fn (MlRankJob $job): array => $job->toArray(),
                $this->jobs,
            ),
            'limit' => $this->limit,
        ];
    }
}

<?php

namespace App\Data\Recommendation;

final readonly class RecommendationPersistenceConfiguration
{
    public function __construct(
        public bool $cacheEnabled,
        public int $successTtlSeconds,
        public int $fallbackTtlSeconds,
        public int $emptyTtlSeconds,
        public int $retentionDays,
        public string $contextVersion,
        public string $cacheSchemaVersion,
        public string $rankingPolicyVersion,
    ) {
        if ($successTtlSeconds < 1
            || $successTtlSeconds > 86400
            || $fallbackTtlSeconds < 1
            || $fallbackTtlSeconds > $successTtlSeconds
            || $emptyTtlSeconds < 1
            || $emptyTtlSeconds > $successTtlSeconds
            || $retentionDays < 1
            || $retentionDays > 365
            || $contextVersion === ''
            || $cacheSchemaVersion === ''
            || $rankingPolicyVersion === '') {
            throw new \InvalidArgumentException(
                'Recommendation persistence configuration is invalid.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function fromArray(array $configuration): self
    {
        return new self(
            cacheEnabled: self::boolean(
                $configuration['cache_enabled'] ?? true,
            ),
            successTtlSeconds: (int) ($configuration['cache_ttl_seconds'] ?? 900),
            fallbackTtlSeconds: (int) (
                $configuration['fallback_cache_ttl_seconds'] ?? 60
            ),
            emptyTtlSeconds: (int) (
                $configuration['empty_cache_ttl_seconds'] ?? 60
            ),
            retentionDays: (int) ($configuration['run_retention_days'] ?? 30),
            contextVersion: (string) (
                $configuration['context_version'] ?? 'recommendation-context-v1'
            ),
            cacheSchemaVersion: (string) (
                $configuration['cache_schema_version']
                    ?? 'recommendation-cache-pointer-v1'
            ),
            rankingPolicyVersion: (string) (
                $configuration['ranking_policy_version']
                    ?? 'raw-score-published-at-job-id-v1'
            ),
        );
    }

    public function ttlFor(RecommendationResult $result): int
    {
        if ($result->candidateCount === 0) {
            return $this->emptyTtlSeconds;
        }

        return $result->engine === RecommendationEngine::MATCHING_V2_FALLBACK
            ? $this->fallbackTtlSeconds
            : $this->successTtlSeconds;
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );
        if (! is_bool($validated)) {
            throw new \InvalidArgumentException(
                'Recommendation cache enabled configuration is invalid.',
            );
        }

        return $validated;
    }
}

<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationResultCacheContract;
use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationPersistenceConfiguration;
use App\Models\RecommendationRun;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;

final readonly class RecommendationResultCache implements RecommendationResultCacheContract
{
    public function __construct(
        private Repository $cache,
        private RecommendationPersistenceConfiguration $configuration,
    ) {}

    public function findRunId(
        int $profileId,
        RecommendationContext $context,
        int $limit,
        Carbon $now,
    ): ?int {
        $key = $this->key($profileId, $context, $limit);
        $pointer = $this->cache->get($key);
        if ($pointer === null) {
            return null;
        }
        $expectedKeys = [
            'schema_version',
            'recommendation_run_id',
            'context_hash',
            'requested_limit',
            'expires_at',
        ];
        $actualKeys = is_array($pointer) ? array_keys($pointer) : [];
        sort($actualKeys);
        $sortedExpectedKeys = $expectedKeys;
        sort($sortedExpectedKeys);
        $expiresAt = is_array($pointer) && is_string($pointer['expires_at'] ?? null)
            ? $this->parseDate($pointer['expires_at'])
            : null;
        if ($actualKeys !== $sortedExpectedKeys
            || $pointer['schema_version'] !== $this->configuration->cacheSchemaVersion
            || ! is_int($pointer['recommendation_run_id'])
            || $pointer['recommendation_run_id'] < 1
            || $pointer['context_hash'] !== $context->hash
            || $pointer['requested_limit'] !== $limit
            || ! $expiresAt instanceof Carbon
            || $expiresAt->lte($now)) {
            $this->cache->forget($key);

            return null;
        }

        return $pointer['recommendation_run_id'];
    }

    public function put(
        int $profileId,
        RecommendationContext $context,
        int $limit,
        RecommendationRun $run,
        Carbon $now,
    ): void {
        $expiresAt = $run->expires_at;
        if (! $expiresAt instanceof Carbon || $expiresAt->lte($now)) {
            return;
        }
        $ttl = max(1, $expiresAt->getTimestamp() - $now->getTimestamp());
        $this->cache->put(
            $this->key($profileId, $context, $limit),
            [
                'schema_version' => $this->configuration->cacheSchemaVersion,
                'recommendation_run_id' => (int) $run->id,
                'context_hash' => $context->hash,
                'requested_limit' => $limit,
                'expires_at' => $expiresAt->toISOString(),
            ],
            $ttl,
        );
    }

    public function forget(
        int $profileId,
        RecommendationContext $context,
        int $limit,
    ): void {
        $this->cache->forget($this->key($profileId, $context, $limit));
    }

    public function key(
        int $profileId,
        RecommendationContext $context,
        int $limit,
    ): string {
        return sprintf(
            'recommendations:v1:profile:%d:limit:%d:context:%s',
            $profileId,
            $limit,
            $context->hash,
        );
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

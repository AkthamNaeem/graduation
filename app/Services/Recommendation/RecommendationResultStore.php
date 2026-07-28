<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationResultHydratorContract;
use App\Contracts\Recommendation\RecommendationResultStoreContract;
use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\Recommendation\RecommendationEngine;
use App\Data\Recommendation\RecommendationPersistenceConfiguration;
use App\Data\Recommendation\RecommendationResult;
use App\Data\Recommendation\RecommendationStoredResult;
use App\Models\RecommendationRun;
use Illuminate\Support\Facades\DB;

final readonly class RecommendationResultStore implements RecommendationResultStoreContract
{
    public function __construct(
        private RecommendationResultHydratorContract $hydrator,
        private RecommendationPersistenceConfiguration $configuration,
    ) {}

    public function findById(
        int $runId,
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationStoredResult {
        $run = $this->lookupQuery($eligibility, $context, $limit)
            ->whereKey($runId)
            ->first();

        return $this->storedResult($run, $eligibility, $context, $limit);
    }

    public function findLatest(
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationStoredResult {
        $run = $this->lookupQuery($eligibility, $context, $limit)
            ->latest('id')
            ->first();

        return $this->storedResult($run, $eligibility, $context, $limit);
    }

    public function persist(
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        RecommendationResult $result,
    ): RecommendationRun {
        $this->validateResultMetadata($result);
        $items = $this->validatedItems($eligibility, $result);
        $generatedAt = $eligibility->now->copy();
        $expiresAt = $generatedAt->copy()->addSeconds(
            $this->configuration->ttlFor($result),
        );

        return DB::transaction(function () use (
            $eligibility,
            $context,
            $result,
            $items,
            $generatedAt,
            $expiresAt,
        ): RecommendationRun {
            $run = RecommendationRun::create([
                'job_seeker_profile_id' => $eligibility->profile->id,
                'request_id' => $result->requestId,
                'context_hash' => $context->hash,
                'context_version' => $context->version,
                'requested_limit' => $result->requestedLimit,
                'candidate_count' => $result->candidateCount,
                'returned_count' => $result->returnedCount,
                'engine' => $result->engine,
                'fallback_used' => $result->fallbackUsed,
                'fallback_code' => $result->safeFallbackCode,
                'model_version' => $result->modelVersion,
                'feature_schema_version' => $result->featureSchemaVersion,
                'explanation_contract_version' => (
                    $result->explanationContractVersion
                ),
                'generated_at' => $generatedAt,
                'expires_at' => $expiresAt,
            ]);
            $run->items()->createMany($items);

            return $run->load('items');
        });
    }

    private function lookupQuery(
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ) {
        return RecommendationRun::query()
            ->with('items')
            ->where('job_seeker_profile_id', $eligibility->profile->id)
            ->where('context_hash', $context->hash)
            ->where('context_version', $context->version)
            ->where('requested_limit', $limit)
            ->where('expires_at', '>', $eligibility->now);
    }

    private function storedResult(
        mixed $run,
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationStoredResult {
        if (! $run instanceof RecommendationRun) {
            return null;
        }
        $result = $this->hydrator->hydrate($run, $eligibility, $context, $limit);

        return $result instanceof RecommendationResult
            ? new RecommendationStoredResult($run, $result)
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validatedItems(
        RecommendationEligibility $eligibility,
        RecommendationResult $result,
    ): array {
        if ($result->candidateCount !== count($eligibility->jobs)
            || $result->returnedCount !== count($result->items)) {
            throw new \InvalidArgumentException('Recommendation persistence counts are invalid.');
        }
        $eligibleIds = array_fill_keys($eligibility->jobIds(), true);
        $seenIds = [];
        $items = [];
        foreach ($result->items as $index => $item) {
            $job = $item['job'] ?? null;
            $jobId = is_object($job) ? (int) $job->id : 0;
            $score = $item['score'] ?? null;
            $rawScore = $item['_persistence_raw_score'] ?? null;
            $matchingVersion = $item['matching_score_version'] ?? null;
            $breakdown = $item['breakdown'] ?? null;
            $reasons = $item['reasons'] ?? null;
            if (! isset($eligibleIds[$jobId])
                || isset($seenIds[$jobId])
                || ($item['rank'] ?? null) !== $index + 1
                || ! is_numeric($score)
                || ! is_finite((float) $score)
                || (float) $score < 0
                || (float) $score > 100
                || ($rawScore !== null
                    && (! is_numeric($rawScore) || ! is_finite((float) $rawScore)))
                || (($result->engine === RecommendationEngine::ML_XGBRANKER)
                    !== ($rawScore !== null))
                || ! is_string($matchingVersion)
                || $matchingVersion === ''
                || strlen($matchingVersion) > 160
                || ($result->engine === RecommendationEngine::ML_XGBRANKER
                    && $matchingVersion
                        !== $result->engine->value.':'.$result->modelVersion)
                || ($breakdown !== null && ! is_array($breakdown))
                || ! is_array($reasons)
                || ! array_is_list($reasons)) {
                throw new \InvalidArgumentException(
                    'Recommendation persistence item is invalid.',
                );
            }
            $seenIds[$jobId] = true;
            $items[] = [
                'job_posting_id' => $jobId,
                'rank' => $index + 1,
                'score' => (float) $score,
                'raw_score' => $rawScore === null ? null : (float) $rawScore,
                'matching_score_version' => $matchingVersion,
                'breakdown' => $breakdown,
                'reasons' => $this->safeReasons($reasons),
                'created_at' => $eligibility->now,
            ];
        }

        return $items;
    }

    private function validateResultMetadata(RecommendationResult $result): void
    {
        $hasMlVersions = $result->modelVersion !== null
            && $result->featureSchemaVersion !== null
            && $result->explanationContractVersion !== null;
        $hasAnyMlVersion = $result->modelVersion !== null
            || $result->featureSchemaVersion !== null
            || $result->explanationContractVersion !== null;

        if (($result->candidateCount > 0 && $result->returnedCount === 0)
            || ($result->candidateCount === 0 && $result->returnedCount !== 0)
            || ($result->engine === RecommendationEngine::ML_XGBRANKER
                && (
                    $result->fallbackUsed
                    || ($result->candidateCount > 0 && ! $hasMlVersions)
                    || ($result->candidateCount === 0 && $hasAnyMlVersion)
                ))
            || ($result->engine === RecommendationEngine::MATCHING_V2
                && ($result->fallbackUsed || $hasAnyMlVersion))
            || ($result->engine === RecommendationEngine::MATCHING_V2_FALLBACK
                && (
                    ! $result->fallbackUsed
                    || ! is_string($result->safeFallbackCode)
                    || $result->safeFallbackCode === ''
                    || $hasAnyMlVersion
                ))) {
            throw new \InvalidArgumentException(
                'Recommendation persistence metadata is invalid.',
            );
        }
    }

    /**
     * @param  list<mixed>  $reasons
     * @return list<array<string, mixed>>
     */
    private function safeReasons(array $reasons): array
    {
        return array_map(function (mixed $reason): array {
            if (! is_array($reason)
                || ! is_string($reason['code'] ?? null)
                || ! is_string($reason['message'] ?? null)) {
                throw new \InvalidArgumentException(
                    'Recommendation persistence reason is invalid.',
                );
            }
            unset($reason['skills']);

            return $reason;
        }, $reasons);
    }
}

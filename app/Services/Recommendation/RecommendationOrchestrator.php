<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationContextFingerprintContract;
use App\Contracts\Recommendation\RecommendationEligibilityProviderContract;
use App\Contracts\Recommendation\RecommendationMlClientFactoryContract;
use App\Contracts\Recommendation\RecommendationMlRequestMapperContract;
use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Contracts\Recommendation\RecommendationResultCacheContract;
use App\Contracts\Recommendation\RecommendationResultStoreContract;
use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\Recommendation\RecommendationEngine;
use App\Data\Recommendation\RecommendationPersistenceConfiguration;
use App\Data\Recommendation\RecommendationResult;
use App\Data\Recommendation\RecommendationStoredResult;
use App\Data\RecommendationMl\MlRankPrediction;
use App\Data\RecommendationMl\MlRankRequest;
use App\Data\RecommendationMl\MlRankResponse;
use App\Exceptions\Recommendation\RecommendationMappingException;
use App\Exceptions\Recommendation\RecommendationReconciliationException;
use App\Exceptions\RecommendationMl\MlRecommendationAuthenticationException;
use App\Exceptions\RecommendationMl\MlRecommendationConfigurationException;
use App\Exceptions\RecommendationMl\MlRecommendationContractException;
use App\Exceptions\RecommendationMl\MlRecommendationException;
use App\Exceptions\RecommendationMl\MlRecommendationTransportException;
use App\Exceptions\RecommendationMl\MlRecommendationUnavailableException;
use App\Exceptions\RecommendationMl\MlRecommendationValidationException;
use App\Models\JobPosting;
use App\Models\RecommendationRun;
use App\Models\User;
use App\Services\MatchingService;
use App\Support\Recommendation\MlRecommendationResourceAdapter;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class RecommendationOrchestrator implements RecommendationOrchestratorContract
{
    public function __construct(
        private RecommendationEligibilityProviderContract $eligibilityProvider,
        private RecommendationMlClientFactoryContract $clientFactory,
        private RecommendationMlRequestMapperContract $requestMapper,
        private MatchingService $matchingService,
        private MlRecommendationResourceAdapter $resourceAdapter,
        private bool $mlEnabled,
        private ?RecommendationContextFingerprintContract $contextFingerprint = null,
        private ?RecommendationResultCacheContract $resultCache = null,
        private ?RecommendationResultStoreContract $resultStore = null,
        private ?RecommendationPersistenceConfiguration $persistenceConfiguration = null,
    ) {}

    public function recommend(User $user, int $limit): RecommendationResult
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Recommendation limit must be positive.');
        }

        $eligibility = $this->eligibilityProvider->eligibleJobs($user, now());
        $context = $this->recommendationContext($eligibility, $limit);
        if ($context instanceof RecommendationContext) {
            $stored = $this->lookupStoredResult($eligibility, $context, $limit);
            if ($stored instanceof RecommendationResult) {
                return $stored;
            }
        }

        $result = $this->compute($user, $eligibility, $limit);
        if ($context instanceof RecommendationContext) {
            $this->rememberResult($eligibility, $context, $result);
        }

        return $result;
    }

    private function compute(
        User $user,
        RecommendationEligibility $eligibility,
        int $limit,
    ): RecommendationResult {
        $candidateCount = count($eligibility->jobs);
        if ($candidateCount === 0) {
            return new RecommendationResult(
                items: [],
                engine: $this->mlEnabled
                    ? RecommendationEngine::ML_XGBRANKER
                    : RecommendationEngine::MATCHING_V2,
                requestedLimit: $limit,
                candidateCount: 0,
                returnedCount: 0,
                fallbackUsed: false,
            );
        }

        if (! $this->mlEnabled) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_DISABLED',
                RecommendationEngine::MATCHING_V2,
                false,
            );
        }

        $requestId = null;
        try {
            $handle = $this->clientFactory->make();
            if ($candidateCount > $handle->configuration->maxJobsPerRequest) {
                return $this->matchingResult(
                    $user,
                    $eligibility,
                    $limit,
                    'ML_CANDIDATE_LIMIT_EXCEEDED',
                );
            }

            $request = $this->requestMapper->map(
                $eligibility,
                $limit,
                $handle->configuration,
            );
            $requestId = $request->requestId;
            $response = $handle->client->rank($request);
            $items = $this->reconcileAndRank(
                $eligibility,
                $request,
                $response,
                $limit,
                $handle->configuration->modelVersion,
                $handle->configuration->featureSchemaVersion,
                $handle->configuration->explanationContractVersion,
            );

            Log::info('recommendation_ml_succeeded', [
                'request_id' => $response->requestId,
                'engine' => RecommendationEngine::ML_XGBRANKER->value,
                'candidate_count' => $candidateCount,
                'returned_count' => count($items),
                'model_version' => $response->modelVersion,
                'feature_schema_version' => $response->featureSchemaVersion,
            ]);

            return new RecommendationResult(
                items: $items,
                engine: RecommendationEngine::ML_XGBRANKER,
                requestedLimit: $limit,
                candidateCount: $candidateCount,
                returnedCount: count($items),
                fallbackUsed: false,
                modelVersion: $response->modelVersion,
                featureSchemaVersion: $response->featureSchemaVersion,
                explanationContractVersion: $response->explanationContractVersion,
                requestId: $response->requestId,
            );
        } catch (MlRecommendationConfigurationException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_CONFIG_INVALID',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (MlRecommendationTransportException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_TRANSPORT_FAILURE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (MlRecommendationAuthenticationException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_AUTHENTICATION_FAILURE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (MlRecommendationValidationException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_PROVIDER_VALIDATION_FAILURE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (MlRecommendationUnavailableException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                $exception->internalCode === 'ML_SERVICE_RATE_LIMITED'
                    ? 'ML_RATE_LIMITED'
                    : 'ML_MODEL_UNAVAILABLE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (MlRecommendationContractException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_CONTRACT_FAILURE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (RecommendationMappingException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_MAPPING_FAILURE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (RecommendationReconciliationException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_RECONCILIATION_FAILURE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        } catch (MlRecommendationException $exception) {
            return $this->matchingResult(
                $user,
                $eligibility,
                $limit,
                'ML_CLIENT_FAILURE',
                exceptionClass: $exception::class,
                requestId: $requestId,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reconcileAndRank(
        RecommendationEligibility $eligibility,
        MlRankRequest $request,
        MlRankResponse $response,
        int $limit,
        string $modelVersion,
        string $featureSchemaVersion,
        string $explanationContractVersion,
    ): array {
        $expectedIds = $eligibility->jobIds();
        $returnedIds = array_map(
            static fn (MlRankPrediction $prediction): int => $prediction->jobId,
            $response->predictions,
        );
        $uniqueReturnedIds = array_values(array_unique($returnedIds, SORT_REGULAR));

        if ($response->requestId !== $request->requestId
            || $response->modelVersion !== $modelVersion
            || $response->featureSchemaVersion !== $featureSchemaVersion
            || $response->explanationContractVersion !== $explanationContractVersion
            || $response->predictionCount !== count($expectedIds)
            || count($returnedIds) !== count($uniqueReturnedIds)
            || array_diff($expectedIds, $uniqueReturnedIds) !== []
            || array_diff($uniqueReturnedIds, $expectedIds) !== []) {
            throw new RecommendationReconciliationException;
        }

        $existingIds = JobPosting::query()
            ->whereKey($expectedIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        sort($existingIds);
        $sortedExpectedIds = $expectedIds;
        sort($sortedExpectedIds);
        if ($existingIds !== $sortedExpectedIds) {
            throw new RecommendationReconciliationException;
        }

        $jobsById = [];
        foreach ($eligibility->jobs as $job) {
            $jobsById[(int) $job->id] = $job;
        }

        $items = [];
        foreach ($response->predictions as $prediction) {
            if (! isset($jobsById[$prediction->jobId])
                || ! is_finite($prediction->rawScore)
                || ! is_finite($prediction->displayScore)
                || $prediction->displayScore < 0
                || $prediction->displayScore > 100) {
                throw new RecommendationReconciliationException;
            }
            $items[] = $this->resourceAdapter->mlItem(
                $jobsById[$prediction->jobId],
                $eligibility->profile,
                $prediction,
                $response,
            );
        }

        usort($items, function (array $left, array $right): int {
            $leftLocationStatus = $left['location_match']['status'] ?? 'missing';
            $rightLocationStatus = $right['location_match']['status'] ?? 'missing';
            if ($leftLocationStatus !== 'missing' || $rightLocationStatus !== 'missing') {
                $displayOrder = $right['score'] <=> $left['score'];
                if ($displayOrder !== 0) {
                    return $displayOrder;
                }
            }

            $scoreOrder = $right['_ml_raw_score'] <=> $left['_ml_raw_score'];
            if ($scoreOrder !== 0) {
                return $scoreOrder;
            }

            $leftPublishedAt = $left['job']->published_at;
            $rightPublishedAt = $right['job']->published_at;
            if ($leftPublishedAt === null && $rightPublishedAt !== null) {
                return 1;
            }
            if ($leftPublishedAt !== null && $rightPublishedAt === null) {
                return -1;
            }
            if ($leftPublishedAt !== null && $rightPublishedAt !== null) {
                $publishedOrder = $rightPublishedAt->getTimestamp()
                    <=> $leftPublishedAt->getTimestamp();
                if ($publishedOrder !== 0) {
                    return $publishedOrder;
                }
            }

            return (int) $left['job']->id <=> (int) $right['job']->id;
        });

        $items = array_slice($items, 0, $limit);
        foreach ($items as $index => &$item) {
            $item['_persistence_raw_score'] = $item['_ml_raw_score'];
            unset($item['_ml_raw_score']);
            $item['rank'] = $index + 1;
        }
        unset($item);

        return $items;
    }

    private function matchingResult(
        User $user,
        RecommendationEligibility $eligibility,
        int $limit,
        string $fallbackCode,
        RecommendationEngine $engine = RecommendationEngine::MATCHING_V2_FALLBACK,
        bool $fallbackUsed = true,
        ?string $exceptionClass = null,
        ?string $requestId = null,
    ): RecommendationResult {
        $eligibleIds = array_fill_keys($eligibility->jobIds(), true);
        $items = $this->matchingService
            ->recommendJobsForUser($user, PHP_INT_MAX)
            ->filter(
                static fn (array $item): bool => isset(
                    $eligibleIds[(int) $item['job']->id],
                ),
            )
            ->take($limit)
            ->values()
            ->map(
                fn (array $item): array => $this->resourceAdapter->matchingItem(
                    $item,
                    $engine,
                    $fallbackUsed,
                ),
            )
            ->all();
        foreach ($items as $index => &$item) {
            $item['rank'] = $index + 1;
        }
        unset($item);

        $context = [
            'request_id' => $requestId,
            'engine' => $engine->value,
            'candidate_count' => count($eligibility->jobs),
            'returned_count' => count($items),
            'fallback_code' => $fallbackCode,
        ];
        if ($exceptionClass !== null) {
            $context['exception_class'] = $exceptionClass;
        }
        Log::warning('recommendation_ml_fallback', $context);

        return new RecommendationResult(
            items: $items,
            engine: $engine,
            requestedLimit: $limit,
            candidateCount: count($eligibility->jobs),
            returnedCount: count($items),
            fallbackUsed: $fallbackUsed,
            safeFallbackCode: $fallbackCode,
            requestId: $requestId,
        );
    }

    private function recommendationContext(
        RecommendationEligibility $eligibility,
        int $limit,
    ): ?RecommendationContext {
        if (! $this->persistenceReady()) {
            return null;
        }

        try {
            return $this->contextFingerprint?->fingerprint(
                $eligibility,
                $this->mlEnabled,
            );
        } catch (Throwable) {
            $this->logStorageFailure(
                'recommendation_persistence_failed',
                'CONTEXT_FINGERPRINT_FAILED',
                $limit,
                count($eligibility->jobs),
            );

            return null;
        }
    }

    private function lookupStoredResult(
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationResult {
        $profileId = (int) $eligibility->profile->id;
        if ($this->persistenceConfiguration?->cacheEnabled) {
            try {
                $runId = $this->resultCache?->findRunId(
                    $profileId,
                    $context,
                    $limit,
                    $eligibility->now,
                );
                if ($runId !== null) {
                    $stored = $this->resultStore?->findById(
                        $runId,
                        $eligibility,
                        $context,
                        $limit,
                    );
                    if ($stored instanceof RecommendationStoredResult) {
                        $result = $stored->result;
                        Log::info('recommendation_cache_hit', [
                            'engine' => $result->engine->value,
                            'requested_limit' => $limit,
                            'candidate_count' => $result->candidateCount,
                            'returned_count' => $result->returnedCount,
                            'cache_hit' => true,
                            'persistence_hit' => false,
                        ]);

                        return $result->withLookupMetadata(true, false);
                    }
                    $this->resultCache?->forget($profileId, $context, $limit);
                    Log::warning('recommendation_cached_result_rejected', [
                        'requested_limit' => $limit,
                        'candidate_count' => count($eligibility->jobs),
                        'failure_code' => 'CACHED_RUN_INVALID',
                    ]);
                }
            } catch (Throwable) {
                $this->logStorageFailure(
                    'recommendation_persistence_failed',
                    'CACHE_LOOKUP_FAILED',
                    $limit,
                    count($eligibility->jobs),
                );
            }
        }

        Log::info('recommendation_cache_miss', [
            'requested_limit' => $limit,
            'candidate_count' => count($eligibility->jobs),
            'cache_hit' => false,
            'persistence_hit' => false,
        ]);
        try {
            $stored = $this->resultStore?->findLatest(
                $eligibility,
                $context,
                $limit,
            );
        } catch (Throwable) {
            $this->logStorageFailure(
                'recommendation_persistence_failed',
                'PERSISTENCE_LOOKUP_FAILED',
                $limit,
                count($eligibility->jobs),
            );

            return null;
        }
        if (! $stored instanceof RecommendationStoredResult) {
            return null;
        }
        $result = $stored->result;

        if ($this->persistenceConfiguration?->cacheEnabled) {
            try {
                $this->resultCache?->put(
                    $profileId,
                    $context,
                    $limit,
                    $stored->run,
                    $eligibility->now,
                );
            } catch (Throwable) {
                $this->logStorageFailure(
                    'recommendation_persistence_failed',
                    'CACHE_WARM_FAILED',
                    $limit,
                    count($eligibility->jobs),
                );
            }
        }
        Log::info('recommendation_persistence_hit', [
            'engine' => $result->engine->value,
            'requested_limit' => $limit,
            'candidate_count' => $result->candidateCount,
            'returned_count' => $result->returnedCount,
            'cache_hit' => false,
            'persistence_hit' => true,
        ]);

        return $result->withLookupMetadata(false, true);
    }

    private function rememberResult(
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        RecommendationResult $result,
    ): void {
        try {
            $run = $this->resultStore?->persist($eligibility, $context, $result);
        } catch (Throwable) {
            $this->logStorageFailure(
                'recommendation_persistence_failed',
                'RESULT_PERSISTENCE_FAILED',
                $result->requestedLimit,
                $result->candidateCount,
            );

            return;
        }
        if (! $run instanceof RecommendationRun) {
            return;
        }
        Log::info('recommendation_result_persisted', [
            'engine' => $result->engine->value,
            'requested_limit' => $result->requestedLimit,
            'candidate_count' => $result->candidateCount,
            'returned_count' => $result->returnedCount,
        ]);
        if (! $this->persistenceConfiguration?->cacheEnabled) {
            return;
        }
        try {
            $this->resultCache?->put(
                (int) $eligibility->profile->id,
                $context,
                $result->requestedLimit,
                $run,
                $eligibility->now,
            );
        } catch (Throwable) {
            $this->logStorageFailure(
                'recommendation_persistence_failed',
                'CACHE_WRITE_FAILED',
                $result->requestedLimit,
                $result->candidateCount,
            );
        }
    }

    private function persistenceReady(): bool
    {
        return $this->contextFingerprint !== null
            && $this->resultCache !== null
            && $this->resultStore !== null
            && $this->persistenceConfiguration !== null;
    }

    private function logStorageFailure(
        string $event,
        string $failureCode,
        int $limit,
        int $candidateCount,
    ): void {
        Log::warning($event, [
            'requested_limit' => $limit,
            'candidate_count' => $candidateCount,
            'failure_code' => $failureCode,
        ]);
    }
}

<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\Recommendation\RecommendationResult;
use App\Data\Recommendation\RecommendationStoredResult;
use App\Models\RecommendationRun;

interface RecommendationResultStoreContract
{
    public function findById(
        int $runId,
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationStoredResult;

    public function findLatest(
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationStoredResult;

    public function persist(
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        RecommendationResult $result,
    ): RecommendationRun;
}

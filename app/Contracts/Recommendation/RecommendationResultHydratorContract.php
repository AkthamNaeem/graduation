<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\Recommendation\RecommendationResult;
use App\Models\RecommendationRun;

interface RecommendationResultHydratorContract
{
    public function hydrate(
        RecommendationRun $run,
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationResult;
}

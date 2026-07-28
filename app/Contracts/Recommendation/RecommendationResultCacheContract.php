<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationContext;
use App\Models\RecommendationRun;
use Illuminate\Support\Carbon;

interface RecommendationResultCacheContract
{
    public function findRunId(
        int $profileId,
        RecommendationContext $context,
        int $limit,
        Carbon $now,
    ): ?int;

    public function put(
        int $profileId,
        RecommendationContext $context,
        int $limit,
        RecommendationRun $run,
        Carbon $now,
    ): void;

    public function forget(
        int $profileId,
        RecommendationContext $context,
        int $limit,
    ): void;
}

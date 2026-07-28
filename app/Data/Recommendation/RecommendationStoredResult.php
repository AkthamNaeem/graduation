<?php

namespace App\Data\Recommendation;

use App\Models\RecommendationRun;

final readonly class RecommendationStoredResult
{
    public function __construct(
        public RecommendationRun $run,
        public RecommendationResult $result,
    ) {}
}

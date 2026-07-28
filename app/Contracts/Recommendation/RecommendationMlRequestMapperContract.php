<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationEligibility;
use App\Data\RecommendationMl\MlClientConfiguration;
use App\Data\RecommendationMl\MlRankRequest;

interface RecommendationMlRequestMapperContract
{
    public function map(
        RecommendationEligibility $eligibility,
        int $requestedLimit,
        MlClientConfiguration $configuration,
    ): MlRankRequest;
}

<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;

interface RecommendationContextFingerprintContract
{
    public function fingerprint(
        RecommendationEligibility $eligibility,
        bool $mlEnabled,
    ): RecommendationContext;
}

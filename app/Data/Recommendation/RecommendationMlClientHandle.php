<?php

namespace App\Data\Recommendation;

use App\Contracts\RecommendationMl\RecommendationMlClientContract;
use App\Data\RecommendationMl\MlClientConfiguration;

final readonly class RecommendationMlClientHandle
{
    public function __construct(
        public RecommendationMlClientContract $client,
        public MlClientConfiguration $configuration,
    ) {}
}

<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationMlClientHandle;

interface RecommendationMlClientFactoryContract
{
    public function make(): RecommendationMlClientHandle;
}

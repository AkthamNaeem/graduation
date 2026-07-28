<?php

namespace App\Data\Recommendation;

enum RecommendationEngine: string
{
    case ML_XGBRANKER = 'ml_xgbranker';
    case MATCHING_V2 = 'matching_v2';
    case MATCHING_V2_FALLBACK = 'matching_v2_fallback';
}

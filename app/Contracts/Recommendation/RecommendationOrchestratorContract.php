<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationResult;
use App\Models\User;

interface RecommendationOrchestratorContract
{
    public function recommend(User $user, int $limit): RecommendationResult;
}

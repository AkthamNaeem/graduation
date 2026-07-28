<?php

namespace App\Contracts\Recommendation;

use App\Data\Recommendation\RecommendationEligibility;
use App\Models\User;
use Illuminate\Support\Carbon;

interface RecommendationEligibilityProviderContract
{
    public function eligibleJobs(User $user, Carbon $now): RecommendationEligibility;
}

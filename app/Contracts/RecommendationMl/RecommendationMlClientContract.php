<?php

namespace App\Contracts\RecommendationMl;

use App\Data\RecommendationMl\MlLivenessResult;
use App\Data\RecommendationMl\MlModelMetadata;
use App\Data\RecommendationMl\MlRankRequest;
use App\Data\RecommendationMl\MlRankResponse;
use App\Data\RecommendationMl\MlReadinessResult;

interface RecommendationMlClientContract
{
    public function live(): MlLivenessResult;

    public function ready(): MlReadinessResult;

    public function metadata(): MlModelMetadata;

    public function rank(MlRankRequest $request): MlRankResponse;
}

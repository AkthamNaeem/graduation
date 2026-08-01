<?php

namespace App\Support\Recommendation;

use App\Data\Recommendation\RecommendationEngine;
use App\Data\RecommendationMl\MlRankPrediction;
use App\Data\RecommendationMl\MlRankResponse;
use App\Enums\JobSkillRequirementType;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Services\LocationCompatibilityService;
use App\Support\SystemGeneratedText;

final class MlRecommendationResourceAdapter
{
    private readonly LocationCompatibilityService $locationCompatibility;

    public function __construct(?LocationCompatibilityService $locationCompatibility = null)
    {
        $this->locationCompatibility = $locationCompatibility ?? new LocationCompatibilityService;
    }

    /**
     * @return array<string, mixed>
     */
    public function mlItem(
        JobPosting $job,
        JobSeekerProfile $profile,
        MlRankPrediction $prediction,
        MlRankResponse $response,
    ): array {
        [$matchedRequired, $missingRequired, $matchedNice] = $this->skillLists(
            $job,
            $profile,
        );
        $positiveCodes = array_map(
            static fn ($factor): string => $factor->code,
            $prediction->topPositiveFactors,
        );
        $negativeCodes = array_map(
            static fn ($factor): string => $factor->code,
            $prediction->topNegativeFactors,
        );
        $matchedNames = collect([...$matchedRequired, ...$matchedNice])
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $location = $this->locationCompatibility->evaluate(
            $job,
            $profile,
            (float) config('matching.components.location', 5),
        );
        $locationIsActionable = $location['status'] !== 'missing';
        $displayScore = $locationIsActionable
            ? round(($prediction->displayScore * (100 - $location['max_score']) / 100) + $location['score'], 2)
            : $prediction->displayScore;

        return [
            'job' => $job,
            'score' => $displayScore,
            'matching_score_version' => RecommendationEngine::ML_XGBRANKER->value
                .':'.$response->modelVersion,
            'breakdown' => [
                'model' => [
                    'display_score' => $prediction->displayScore,
                    'score_semantics' => 'ranking_score_not_probability',
                    'explanation_note' => SystemGeneratedText::resolve($response->explanationNote),
                    'positive_reason_codes' => $positiveCodes,
                    'negative_reason_codes' => $negativeCodes,
                ],
                'location' => $location,
            ],
            'matched_skills' => $matchedNames,
            'skill_breakdown' => [
                'required_skills_matched' => collect($matchedRequired)->pluck('name')->all(),
                'required_skills_missing' => collect($missingRequired)->pluck('name')->all(),
                'optional_skills_matched' => collect($matchedNice)->pluck('name')->all(),
                'nice_to_have_skills_matched' => collect($matchedNice)->pluck('name')->all(),
            ],
            'matched_required_skills' => $matchedRequired,
            'missing_required_skills' => $missingRequired,
            'matched_nice_to_have_skills' => $matchedNice,
            'location_match' => $location,
            'reasons' => array_map(
                fn (string $code): array => [
                    'code' => $code,
                    'message' => __('ai.reasons.'.$code),
                ],
                array_values(array_unique([
                    ...$positiveCodes,
                    ...$negativeCodes,
                    $location['reason_code'],
                ])),
            ),
            'recommendation_engine' => RecommendationEngine::ML_XGBRANKER->value,
            'model_version' => $response->modelVersion,
            'feature_schema_version' => $response->featureSchemaVersion,
            'explanation_contract_version' => $response->explanationContractVersion,
            'fallback_used' => false,
            '_ml_raw_score' => $prediction->rawScore,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function matchingItem(
        array $item,
        RecommendationEngine $engine,
        bool $fallbackUsed,
    ): array {
        return [
            ...$item,
            'recommendation_engine' => $engine->value,
            'model_version' => null,
            'feature_schema_version' => null,
            'explanation_contract_version' => null,
            'fallback_used' => $fallbackUsed,
        ];
    }

    /**
     * @return array{
     *     0: list<array{id: int, name: string, weight: int}>,
     *     1: list<array{id: int, name: string, weight: int}>,
     *     2: list<array{id: int, name: string, weight: int}>
     * }
     */
    private function skillLists(JobPosting $job, JobSeekerProfile $profile): array
    {
        $candidateIds = $profile->skills
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();
        $matchedRequired = [];
        $missingRequired = [];
        $matchedNice = [];

        foreach ($job->skills->unique('id') as $skill) {
            $rawType = $skill->pivot->requirement_type;
            $type = JobSkillRequirementType::normalize(
                $rawType instanceof JobSkillRequirementType
                    ? $rawType->value
                    : (string) $rawType,
            );
            if ($type === null) {
                continue;
            }
            $item = [
                'id' => (int) $skill->id,
                'name' => (string) $skill->name,
                'weight' => (int) $skill->pivot->weight,
            ];

            if ($type->isRequired()) {
                $candidateIds->has((int) $skill->id)
                    ? $matchedRequired[] = $item
                    : $missingRequired[] = $item;
            } elseif ($candidateIds->has((int) $skill->id)) {
                $matchedNice[] = $item;
            }
        }

        return [$matchedRequired, $missingRequired, $matchedNice];
    }
}

<?php

namespace App\Support\Recommendation;

use App\Data\Recommendation\RecommendationEngine;
use App\Data\RecommendationMl\MlRankPrediction;
use App\Data\RecommendationMl\MlRankResponse;
use App\Enums\JobSkillRequirementType;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;

final class MlRecommendationResourceAdapter
{
    private const REASON_MESSAGES = [
        'DOMAIN_MISMATCH' => 'The professional domain is not closely aligned.',
        'DOMAIN_ALIGNMENT' => 'The professional domain is aligned.',
        'OPTIONAL_SKILLS_GAP' => 'Some optional skill alignment is missing.',
        'OPTIONAL_SKILLS_ALIGNMENT' => 'Optional skills support this ranking.',
        'REQUIRED_SKILLS_GAP' => 'Some required skill alignment is missing.',
        'REQUIRED_SKILLS_ALIGNMENT' => 'Required skills support this ranking.',
        'COMBINED_PROFILE_GAP' => 'Combined professional factors reduce the ranking score.',
        'COMBINED_PROFILE_ALIGNMENT' => 'Combined professional factors support the ranking.',
        'CAREER_LEVEL_GAP' => 'Career-level alignment is limited.',
        'CAREER_LEVEL_ALIGNMENT' => 'Career level supports this ranking.',
        'EXPERIENCE_GAP' => 'Experience alignment is limited.',
        'EXPERIENCE_ALIGNMENT' => 'Experience supports this ranking.',
        'EDUCATION_GAP' => 'Education alignment is limited.',
        'EDUCATION_ALIGNMENT' => 'Education supports this ranking.',
        'WORK_PREFERENCE_MISMATCH' => 'Work preferences are not fully aligned.',
        'WORK_PREFERENCE_ALIGNMENT' => 'Work preferences support this ranking.',
        'TEXT_MISMATCH' => 'Professional text alignment is limited.',
        'TEXT_ALIGNMENT' => 'Professional text supports this ranking.',
        'PROFILE_DATA_MISSING' => 'Some professional profile data is missing.',
        'PROFILE_DATA_COMPLETENESS' => 'Professional profile completeness supports this ranking.',
    ];

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

        return [
            'job' => $job,
            'score' => $prediction->displayScore,
            'matching_score_version' => RecommendationEngine::ML_XGBRANKER->value
                .':'.$response->modelVersion,
            'breakdown' => [
                'model' => [
                    'display_score' => $prediction->displayScore,
                    'score_semantics' => 'ranking_score_not_probability',
                    'explanation_note' => $response->explanationNote,
                    'positive_reason_codes' => $positiveCodes,
                    'negative_reason_codes' => $negativeCodes,
                ],
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
            'reasons' => array_map(
                fn (string $code): array => [
                    'code' => $code,
                    'message' => self::REASON_MESSAGES[$code],
                ],
                array_values(array_unique([...$positiveCodes, ...$negativeCodes])),
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

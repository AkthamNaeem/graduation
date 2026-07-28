<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationResultHydratorContract;
use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\Recommendation\RecommendationEngine;
use App\Data\Recommendation\RecommendationResult;
use App\Enums\JobSkillRequirementType;
use App\Models\JobPosting;
use App\Models\RecommendationRun;
use Throwable;

final readonly class RecommendationResultHydrator implements RecommendationResultHydratorContract
{
    /**
     * @param  array<string, mixed>  $mlConfiguration
     */
    public function __construct(
        private array $mlConfiguration,
        private string $matchingVersion,
    ) {}

    public function hydrate(
        RecommendationRun $run,
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationResult {
        try {
            return $this->hydrateValidated($run, $eligibility, $context, $limit);
        } catch (Throwable) {
            return null;
        }
    }

    private function hydrateValidated(
        RecommendationRun $run,
        RecommendationEligibility $eligibility,
        RecommendationContext $context,
        int $limit,
    ): ?RecommendationResult {
        if (! $run->relationLoaded('items')
            || (int) $run->job_seeker_profile_id !== (int) $eligibility->profile->id
            || $run->context_hash !== $context->hash
            || $run->context_version !== $context->version
            || (int) $run->requested_limit !== $limit
            || ! $run->expires_at?->gt($eligibility->now)
            || (int) $run->candidate_count !== count($eligibility->jobs)
            || (int) $run->returned_count !== $run->items->count()
            || (int) $run->returned_count > $limit
            || (int) $run->returned_count > count($eligibility->jobs)
            || ! $run->engine instanceof RecommendationEngine
            || ! $this->versionsSupported($run)) {
            return null;
        }

        $jobsById = [];
        foreach ($eligibility->jobs as $job) {
            $jobsById[(int) $job->id] = $job;
        }
        $seenJobIds = [];
        $items = [];
        foreach ($run->items->values() as $index => $stored) {
            $jobId = (int) $stored->job_posting_id;
            $score = (float) $stored->score;
            $rawScore = $stored->raw_score === null ? null : (float) $stored->raw_score;
            if ((int) $stored->rank !== $index + 1
                || isset($seenJobIds[$jobId])
                || ! isset($jobsById[$jobId])
                || ! is_finite($score)
                || $score < 0
                || $score > 100
                || ($rawScore !== null && ! is_finite($rawScore))
                || ! is_string($stored->matching_score_version)
                || $stored->matching_score_version === ''
                || ($stored->breakdown !== null && ! is_array($stored->breakdown))
                || ! is_array($stored->reasons)
                || ! $this->scoreVersionSupported($run->engine, $stored->matching_score_version)) {
                return null;
            }
            $seenJobIds[$jobId] = true;
            $job = $jobsById[$jobId];
            $skillProjection = $this->skillProjection($job, $eligibility);
            if ($skillProjection === null) {
                return null;
            }
            $reasons = $this->hydrateReasons(
                $stored->reasons,
                $skillProjection['missing_required_skills'],
            );
            if ($reasons === null) {
                return null;
            }
            $item = [
                'job' => $job,
                'score' => $score,
                'matching_score_version' => $stored->matching_score_version,
                'breakdown' => $stored->breakdown,
                'matched_skills' => $skillProjection['matched_skills'],
                'skill_breakdown' => $skillProjection['skill_breakdown'],
                'matched_required_skills' => $skillProjection['matched_required_skills'],
                'missing_required_skills' => $skillProjection['missing_required_skills'],
                'matched_nice_to_have_skills' => (
                    $skillProjection['matched_nice_to_have_skills']
                ),
                'reasons' => $reasons,
                'rank' => $index + 1,
                'recommendation_engine' => $run->engine->value,
                'model_version' => $run->model_version,
                'feature_schema_version' => $run->feature_schema_version,
                'explanation_contract_version' => $run->explanation_contract_version,
                'fallback_used' => (bool) $run->fallback_used,
            ];
            if ($rawScore !== null) {
                $item['_persistence_raw_score'] = $rawScore;
            }
            $items[] = $item;
        }

        return new RecommendationResult(
            items: $items,
            engine: $run->engine,
            requestedLimit: $limit,
            candidateCount: (int) $run->candidate_count,
            returnedCount: (int) $run->returned_count,
            fallbackUsed: (bool) $run->fallback_used,
            safeFallbackCode: $run->fallback_code,
            modelVersion: $run->model_version,
            featureSchemaVersion: $run->feature_schema_version,
            explanationContractVersion: $run->explanation_contract_version,
            requestId: $run->request_id,
        );
    }

    private function versionsSupported(RecommendationRun $run): bool
    {
        if ((int) $run->candidate_count === 0) {
            return $run->model_version === null
                && $run->feature_schema_version === null
                && $run->explanation_contract_version === null
                && ! $run->fallback_used
                && in_array(
                    $run->engine,
                    [
                        RecommendationEngine::ML_XGBRANKER,
                        RecommendationEngine::MATCHING_V2,
                    ],
                    true,
                );
        }

        if ($run->engine === RecommendationEngine::ML_XGBRANKER) {
            return $run->model_version === ($this->mlConfiguration['model_version'] ?? null)
                && $run->feature_schema_version
                    === ($this->mlConfiguration['feature_schema_version'] ?? null)
                && $run->explanation_contract_version
                    === ($this->mlConfiguration['explanation_contract_version'] ?? null)
                && ! $run->fallback_used;
        }

        return $run->model_version === null
            && $run->feature_schema_version === null
            && $run->explanation_contract_version === null
            && (
                ($run->engine === RecommendationEngine::MATCHING_V2
                    && ! $run->fallback_used)
                || ($run->engine === RecommendationEngine::MATCHING_V2_FALLBACK
                    && $run->fallback_used)
            );
    }

    private function scoreVersionSupported(
        RecommendationEngine $engine,
        string $version,
    ): bool {
        return $engine === RecommendationEngine::ML_XGBRANKER
            ? $version === $engine->value.':'.($this->mlConfiguration['model_version'] ?? '')
            : $version === $this->matchingVersion;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function skillProjection(
        JobPosting $job,
        RecommendationEligibility $eligibility,
    ): ?array {
        if (! $job->relationLoaded('skills')
            || ! $eligibility->profile->relationLoaded('skills')) {
            return null;
        }
        $candidateIds = $eligibility->profile->skills
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
            $weight = (int) $skill->pivot->weight;
            if ($type === null || $weight < 1 || $weight > 5) {
                return null;
            }
            $projection = [
                'id' => (int) $skill->id,
                'name' => (string) $skill->name,
                'weight' => $weight,
            ];
            if ($type->isRequired()) {
                $candidateIds->has((int) $skill->id)
                    ? $matchedRequired[] = $projection
                    : $missingRequired[] = $projection;
            } elseif ($candidateIds->has((int) $skill->id)) {
                $matchedNice[] = $projection;
            }
        }
        $matchedNames = collect([...$matchedRequired, ...$matchedNice])
            ->pluck('name')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
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
        ];
    }

    /**
     * @param  list<mixed>  $reasons
     * @param  list<array{id: int, name: string, weight: int}>  $missingRequired
     * @return list<array<string, mixed>>|null
     */
    private function hydrateReasons(array $reasons, array $missingRequired): ?array
    {
        if (! array_is_list($reasons)) {
            return null;
        }
        $result = [];
        foreach ($reasons as $reason) {
            if (! is_array($reason)
                || ! is_string($reason['code'] ?? null)
                || ! is_string($reason['message'] ?? null)) {
                return null;
            }
            if ($reason['code'] === 'MISSING_REQUIRED_SKILLS') {
                $reason['skills'] = array_column($missingRequired, 'name');
            }
            $result[] = $reason;
        }

        return $result;
    }
}

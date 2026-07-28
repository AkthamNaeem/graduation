<?php

namespace Tests\Feature\Concerns;

use App\Contracts\Recommendation\RecommendationContextFingerprintContract;
use App\Contracts\Recommendation\RecommendationEligibilityProviderContract;
use App\Data\Recommendation\RecommendationContext;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\Recommendation\RecommendationEngine;
use App\Data\Recommendation\RecommendationResult;
use App\Data\RecommendationMl\MlRankResponse;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait BuildsRecommendationPersistenceScenarios
{
    protected const ML_URL = 'http://ml.persistence.test';

    protected const ML_TOKEN = 'recommendation-persistence-test-token';

    protected function setUpRecommendationPersistenceScenario(
        bool $mlEnabled = true,
    ): void {
        $this->seed(ApplicationStatusSeeder::class);
        Carbon::setTestNow('2026-07-25 12:00:00');
        Http::preventStrayRequests();
        $this->configureRecommendationMl($mlEnabled);
    }

    protected function tearDownRecommendationPersistenceScenario(): void
    {
        Carbon::setTestNow();
    }

    protected function configureRecommendationMl(
        bool $enabled = true,
        array $overrides = [],
    ): void {
        config()->set('recommendation_ml', array_replace_recursive([
            'enabled' => $enabled,
            'base_url' => self::ML_URL,
            'service_token' => self::ML_TOKEN,
            'connect_timeout_seconds' => 1,
            'timeout_seconds' => 2,
            'max_jobs_per_request' => 500,
            'max_results' => 100,
            'api_contract_version' => 'recommendation-ranking-api-v1',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'feature_schema_version' => 'job-rec-features-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
            'persistence' => [
                'cache_enabled' => true,
                'cache_ttl_seconds' => 900,
                'fallback_cache_ttl_seconds' => 60,
                'empty_cache_ttl_seconds' => 60,
                'run_retention_days' => 30,
                'context_version' => 'recommendation-context-v1',
                'cache_schema_version' => 'recommendation-cache-pointer-v1',
                'ranking_policy_version' => 'raw-score-published-at-job-id-v1',
            ],
        ], $overrides));
    }

    protected function recommendationUser(
        array $profileOverrides = [],
        array $userOverrides = [],
    ): User {
        $user = User::factory()->create(array_merge([
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
        ], $userOverrides));
        JobSeekerProfile::create(array_merge([
            'user_id' => $user->id,
            'headline' => 'Backend Engineer',
            'summary' => 'Builds reliable platforms.',
        ], $profileOverrides));

        return $user->load('jobSeekerProfile');
    }

    protected function recommendationCompany(
        string $approvalStatus = 'approved',
    ): Company {
        return Company::create([
            'name' => 'Recommendation Company '.Str::uuid(),
            'approval_status' => $approvalStatus,
        ]);
    }

    protected function recommendationJob(
        Company $company,
        array $overrides = [],
    ): JobPosting {
        return JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Platform Engineer '.Str::random(6),
            'department' => 'Engineering',
            'description' => 'Build reliable recruitment services.',
            'responsibilities' => 'Design and operate services.',
            'requirements' => 'Professional backend experience.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'education_level' => 'bachelor',
            'work_mode' => 'remote',
            'status' => 'open',
            'published_at' => now()->subDay(),
            'application_deadline' => null,
        ], $overrides));
    }

    protected function recommendationEligibility(
        User $user,
    ): RecommendationEligibility {
        return app(RecommendationEligibilityProviderContract::class)
            ->eligibleJobs($user->fresh(), now());
    }

    protected function recommendationContext(
        RecommendationEligibility $eligibility,
        ?bool $mlEnabled = null,
    ): RecommendationContext {
        return app(RecommendationContextFingerprintContract::class)
            ->fingerprint(
                $eligibility,
                $mlEnabled ?? (bool) config('recommendation_ml.enabled'),
            );
    }

    protected function storedResultFixture(
        RecommendationEligibility $eligibility,
        RecommendationEngine $engine,
        int $limit = 10,
    ): RecommendationResult {
        $items = [];
        foreach (array_slice($eligibility->jobs, 0, $limit) as $index => $job) {
            $items[] = [
                'job' => $job,
                'score' => 91.25 - $index,
                'matching_score_version' => (
                    $engine === RecommendationEngine::ML_XGBRANKER
                        ? 'ml_xgbranker:xgbranker-tuned-v1'
                        : '2.0'
                ),
                'breakdown' => ['skills' => ['score' => 82.5]],
                'matched_skills' => [],
                'skill_breakdown' => [
                    'required_skills_matched' => [],
                    'required_skills_missing' => [],
                    'optional_skills_matched' => [],
                    'nice_to_have_skills_matched' => [],
                ],
                'matched_required_skills' => [],
                'missing_required_skills' => [],
                'matched_nice_to_have_skills' => [],
                'reasons' => [[
                    'code' => 'DOMAIN_ALIGNMENT',
                    'message' => 'Professional domain alignment supports this ranking.',
                ]],
                'rank' => $index + 1,
                'recommendation_engine' => $engine->value,
                'model_version' => (
                    $engine === RecommendationEngine::ML_XGBRANKER
                        ? 'xgbranker-tuned-v1'
                        : null
                ),
                'feature_schema_version' => (
                    $engine === RecommendationEngine::ML_XGBRANKER
                        ? 'job-rec-features-v1'
                        : null
                ),
                'explanation_contract_version' => (
                    $engine === RecommendationEngine::ML_XGBRANKER
                        ? 'recommendation-explanation-contract-v1'
                        : null
                ),
                'fallback_used' => (
                    $engine === RecommendationEngine::MATCHING_V2_FALLBACK
                ),
                ...($engine === RecommendationEngine::ML_XGBRANKER
                    ? ['_persistence_raw_score' => 3.125 - $index]
                    : []),
            ];
        }

        return new RecommendationResult(
            items: $items,
            engine: $engine,
            requestedLimit: $limit,
            candidateCount: count($eligibility->jobs),
            returnedCount: count($items),
            fallbackUsed: $engine === RecommendationEngine::MATCHING_V2_FALLBACK,
            safeFallbackCode: (
                $engine === RecommendationEngine::MATCHING_V2_FALLBACK
                    ? 'ML_TRANSPORT_FAILURE'
                    : ($engine === RecommendationEngine::MATCHING_V2
                        ? 'ML_DISABLED'
                        : null)
            ),
            modelVersion: (
                $engine === RecommendationEngine::ML_XGBRANKER
                    ? 'xgbranker-tuned-v1'
                    : null
            ),
            featureSchemaVersion: (
                $engine === RecommendationEngine::ML_XGBRANKER
                    ? 'job-rec-features-v1'
                    : null
            ),
            explanationContractVersion: (
                $engine === RecommendationEngine::ML_XGBRANKER
                    ? 'recommendation-explanation-contract-v1'
                    : null
            ),
            requestId: (
                $engine === RecommendationEngine::ML_XGBRANKER
                    ? (string) Str::uuid()
                    : null
            ),
        );
    }

    protected function fakeSuccessfulRecommendationMl(): void
    {
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => (
                fn (Request $request) => Http::response(
                    $this->validRecommendationRankResponse($request->data()),
                )
            ),
        ]);
    }

    protected function validRecommendationRankResponse(array $payload): array
    {
        $predictions = [];
        foreach ($payload['jobs'] as $index => $job) {
            $predictions[] = [
                'job_id' => $job['job_id'],
                'rank' => $index + 1,
                'raw_score' => 1000.0 - $index,
                'display_score' => 90.0 - $index,
                'top_positive_factors' => [[
                    'code' => 'DOMAIN_ALIGNMENT',
                    'feature_group' => 'domain_compatibility',
                    'direction' => 'increases_model_score',
                    'contribution' => 0.5,
                    'strength' => 0.8,
                ]],
                'top_negative_factors' => [],
            ];
        }

        return [
            'request_id' => $payload['request_id'],
            'api_contract_version' => 'recommendation-ranking-api-v1',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'dataset_version' => 'synthetic-job-rec-1.0.0',
            'feature_schema_version' => 'job-rec-features-v1',
            'model_source_revision' => str_repeat('a', 40),
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'requested_limit' => $payload['limit'],
            'prediction_count' => count($predictions),
            'predictions' => $predictions,
            'explanation_note' => MlRankResponse::EXPLANATION_NOTE,
            'latency_ms' => 1.0,
        ];
    }
}

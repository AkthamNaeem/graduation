<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Data\RecommendationMl\MlRankResponse;
use App\Enums\UserRole;
use App\Models\ApplicationStatus;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecommendedJobsMlTest extends TestCase
{
    use RefreshDatabase;

    private const ML_URL = 'http://ml.internal:8100';

    private const ML_TOKEN = 'phase-14-test-token-with-32-characters';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ApplicationStatusSeeder::class);
        Carbon::setTestNow('2026-07-25 12:00:00');
        Http::preventStrayRequests();
        $this->configureMl();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ml_success_preserves_public_contract_and_uses_one_rank_call(): void
    {
        $user = $this->jobSeeker();
        $company = $this->company();
        $skill = Skill::create(['name' => 'Laravel', 'slug' => 'laravel']);
        $user->jobSeekerProfile->skills()->attach($skill);
        $job = $this->job($company, [
            'title' => 'Laravel Engineer',
            'published_at' => now()->subHour(),
        ]);
        $job->skills()->attach($skill, [
            'requirement_type' => 'required',
            'weight' => 5,
        ]);
        $capturedPayload = null;
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => function (Request $request) use (&$capturedPayload) {
                $capturedPayload = $request->data();

                return Http::response($this->validRankResponse($capturedPayload));
            },
        ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended?limit=10');

        $response->assertOk()
            ->assertExactJsonStructure([
                'success',
                'message',
                'data' => [[
                    'id',
                    'company_id',
                    'title',
                    'department',
                    'description',
                    'responsibilities',
                    'requirements',
                    'benefits',
                    'employment_type',
                    'experience_level',
                    'education_level',
                    'location',
                    'work_mode',
                    'salary_min',
                    'salary_max',
                    'status',
                    'published_at',
                    'application_deadline',
                    'has_application_deadline',
                    'is_application_deadline_passed',
                    'is_accepting_applications',
                    'can_apply',
                    'company',
                    'skills',
                    'required_skills',
                    'nice_to_have_skills',
                    'created_at',
                    'updated_at',
                    'score',
                    'matching_score_version',
                    'breakdown',
                    'matched_skills',
                    'skill_breakdown',
                    'matched_required_skills',
                    'missing_required_skills',
                    'matched_nice_to_have_skills',
                    'reasons',
                    'rank',
                    'recommendation_engine',
                    'model_version',
                    'feature_schema_version',
                    'explanation_contract_version',
                    'fallback_used',
                ]],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Recommended jobs retrieved successfully.')
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.score', 90)
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonPath('data.0.recommendation_engine', 'ml_xgbranker')
            ->assertJsonPath('data.0.matching_score_version', 'ml_xgbranker:xgbranker-tuned-v1')
            ->assertJsonPath('data.0.fallback_used', false)
            ->assertJsonPath('data.0.breakdown.model.score_semantics', 'ranking_score_not_probability')
            ->assertJsonPath('data.0.reasons.0.code', 'DOMAIN_ALIGNMENT')
            ->assertJsonMissingPath('data.0.safe_fallback_code');

        $this->assertNotNull($capturedPayload);
        $this->assertSame([$job->id], array_column($capturedPayload['jobs'], 'job_id'));
        $this->assertSame(1, $capturedPayload['limit']);
        $this->assertTrue(Str::isUuid($capturedPayload['request_id']));
        $this->assertSame('job-rec-features-v1', $capturedPayload['feature_schema_version']);
        $body = $response->getContent();
        $this->assertStringNotContainsString('feature_group', $body);
        $this->assertStringNotContainsString('contribution', $body);
        $this->assertStringNotContainsString('raw_score', $body);
        $this->assertStringNotContainsString(self::ML_TOKEN, $body);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::ML_URL.'/v1/recommendations/rank'
            && $request->hasHeader('X-ML-Service-Token', self::ML_TOKEN));
    }

    public function test_laravel_eligibility_is_complete_and_sent_without_silent_truncation(): void
    {
        $user = $this->jobSeeker();
        $approved = $this->company('approved');
        $eligibleNull = $this->job($approved, ['application_deadline' => null]);
        $eligibleFuture = $this->job($approved, ['application_deadline' => now()->addSecond()]);
        $eligibleBoundary = $this->job($approved, ['application_deadline' => now()]);
        $eligibleUnpublished = $this->job($approved, ['published_at' => null]);

        $this->job($approved, ['status' => 'draft']);
        $this->job($approved, ['status' => 'closed']);
        $this->job($approved, ['application_deadline' => now()->subSecond()]);
        foreach (['pending', 'rejected', 'suspended'] as $approvalStatus) {
            $this->job($this->company($approvalStatus));
        }

        $priorJobIds = [];
        foreach (ApplicationStatus::query()->orderBy('id')->get() as $status) {
            $priorJob = $this->job($approved);
            $priorJobIds[] = $priorJob->id;
            JobApplication::create([
                'job_posting_id' => $priorJob->id,
                'job_seeker_profile_id' => $user->jobSeekerProfile->id,
                'application_status_id' => $status->id,
            ]);
        }

        $sentIds = [];
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => function (Request $request) use (&$sentIds) {
                $payload = $request->data();
                $sentIds = array_column($payload['jobs'], 'job_id');

                return Http::response($this->validRankResponse($payload));
            },
        ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended?limit=50')
            ->assertOk()
            ->assertJsonCount(4, 'data');

        $expectedIds = [
            $eligibleNull->id,
            $eligibleFuture->id,
            $eligibleBoundary->id,
            $eligibleUnpublished->id,
        ];
        sort($expectedIds);
        $this->assertSame($expectedIds, $sentIds);
        foreach ($priorJobIds as $priorJobId) {
            $response->assertJsonMissing(['id' => $priorJobId]);
        }
        Http::assertSentCount(1);
    }

    public function test_zero_eligible_jobs_returns_empty_without_ml_or_matching_work(): void
    {
        $user = $this->jobSeeker();
        $this->job($this->company(), ['status' => 'closed']);
        Http::fake();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Recommended jobs retrieved successfully.')
            ->assertJsonCount(0, 'data');

        Http::assertNothingSent();
    }

    public function test_exact_candidate_max_uses_one_ml_call(): void
    {
        $user = $this->jobSeeker();
        $company = $this->company();
        $this->job($company);
        $this->job($company);
        $this->configureMl(maxJobs: 2, maxResults: 2);
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => fn (Request $request) => Http::response(
                $this->validRankResponse($request->data()),
            ),
        ]);

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.recommendation_engine', 'ml_xgbranker');
        Http::assertSentCount(1);
    }

    public function test_candidate_pool_over_max_uses_full_matching_fallback_without_ml_call(): void
    {
        $user = $this->jobSeeker();
        $company = $this->company();
        $first = $this->job($company);
        $second = $this->job($company);
        $this->configureMl(maxJobs: 1, maxResults: 1);
        Http::fake();

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.recommendation_engine', 'matching_v2_fallback')
            ->assertJsonPath('data.0.fallback_used', true);
        $returnedIds = array_column($response->json('data'), 'id');
        sort($returnedIds);
        $this->assertSame([$first->id, $second->id], $returnedIds);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function test_enabled_invalid_configuration_falls_back_without_network(
        string $key,
        mixed $value,
    ): void {
        $user = $this->jobSeeker();
        $this->job($this->company());
        config()->set('recommendation_ml.'.$key, $value);
        Http::fake();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended')
            ->assertOk()
            ->assertJsonPath('data.0.recommendation_engine', 'matching_v2_fallback')
            ->assertJsonPath('data.0.fallback_used', true)
            ->assertJsonMissingPath('data.0.safe_fallback_code');

        Http::assertNothingSent();
    }

    public static function invalidConfigurationProvider(): array
    {
        return [
            'missing token' => ['service_token', null],
            'invalid URL' => ['base_url', 'not a valid URL'],
        ];
    }

    public function test_disabled_ml_is_lazy_and_preserves_matching_v2_output(): void
    {
        $user = $this->jobSeeker();
        $job = $this->job($this->company());
        config()->set('recommendation_ml.enabled', false);
        config()->set('recommendation_ml.service_token', null);
        config()->set('recommendation_ml.base_url', 'invalid');
        Http::fake();

        $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended')
            ->assertOk()
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.matching_score_version', '2.0')
            ->assertJsonPath('data.0.recommendation_engine', 'matching_v2')
            ->assertJsonPath('data.0.fallback_used', false)
            ->assertJsonStructure([
                'data' => [[
                    'breakdown',
                    'matched_skills',
                    'skill_breakdown',
                    'matched_required_skills',
                    'missing_required_skills',
                    'matched_nice_to_have_skills',
                    'reasons',
                ]],
            ]);

        Http::assertNothingSent();
    }

    #[DataProvider('providerFailureProvider')]
    public function test_provider_failure_matrix_returns_safe_matching_fallback(
        string $failure,
    ): void {
        $user = $this->jobSeeker();
        $job = $this->job($this->company());
        Log::spy();
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => function () use ($failure) {
                if ($failure === 'connection') {
                    return Http::failedConnection();
                }
                if ($failure === 'malformed') {
                    return Http::response(['unexpected' => true]);
                }

                return Http::response(['code' => 'SAFE_PROVIDER_ERROR'], (int) $failure);
            },
        ]);

        $response = $this->withToken($this->tokenFor($user))
            ->getJson('/api/v1/jobs/recommended');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.recommendation_engine', 'matching_v2_fallback')
            ->assertJsonPath('data.0.matching_score_version', '2.0')
            ->assertJsonPath('data.0.fallback_used', true)
            ->assertJsonMissingPath('data.0.safe_fallback_code');
        $body = $response->getContent();
        $this->assertStringNotContainsString('ML_', $body);
        $this->assertStringNotContainsString(self::ML_URL, $body);
        $this->assertStringNotContainsString(self::ML_TOKEN, $body);
        Http::assertSentCount(1);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            function (string $event, array $context): bool {
                $this->assertSame('recommendation_ml_fallback', $event);
                $this->assertSame([
                    'request_id',
                    'engine',
                    'candidate_count',
                    'returned_count',
                    'fallback_code',
                    'exception_class',
                ], array_keys($context));
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString(self::ML_TOKEN, $encoded);
                $this->assertStringNotContainsString(self::ML_URL, $encoded);
                $this->assertStringNotContainsString($userEmail = 'jobseeker@example.com', $encoded);
                $this->assertStringStartsWith('ML_', $context['fallback_code']);

                return true;
            },
        );
    }

    public static function providerFailureProvider(): array
    {
        return [
            'connection timeout/transport' => ['connection'],
            'authentication' => ['401'],
            'provider validation' => ['422'],
            'rate limited' => ['429'],
            'model unavailable' => ['503'],
            'server error' => ['500'],
            'malformed contract' => ['malformed'],
        ];
    }

    public function test_endpoint_keeps_auth_role_limit_route_and_middleware_contracts(): void
    {
        $route = Route::getRoutes()->getByName('v1.jobs.recommended');
        $this->assertNotNull($route);
        $this->assertSame('api/v1/jobs/recommended', $route->uri());
        $middleware = $route->gatherMiddleware();
        foreach ([
            'api',
            'auth:sanctum',
            'user.active',
            'company.approved',
        ] as $expectedMiddleware) {
            $this->assertContains($expectedMiddleware, $middleware);
        }

        $this->getJson('/api/v1/jobs/recommended')->assertUnauthorized();

        $jobSeeker = $this->jobSeeker();
        $this->withToken($this->tokenFor($jobSeeker))
            ->getJson('/api/v1/jobs/recommended?limit=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);

        $employer = $this->employer();
        $this->withToken($this->tokenFor($employer))
            ->getJson('/api/v1/jobs/recommended')
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_suspended_job_seekers_cannot_reach_recommendations(): void
    {
        Http::fake();
        $suspended = $this->jobSeeker('suspended@example.com');
        $suspended->update(['status' => 'suspended']);
        $this->withToken($this->tokenFor($suspended))
            ->getJson('/api/v1/jobs/recommended')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        Http::assertNothingSent();
    }

    public function test_active_job_seeker_profile_is_required_for_recommendations(): void
    {
        Http::fake();
        $withoutProfile = User::factory()->create([
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
        ]);
        $this->withToken($this->tokenFor($withoutProfile))
            ->getJson('/api/v1/jobs/recommended')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['job_seeker_profile']);

        Http::assertNothingSent();
    }

    public function test_ml_endpoint_performs_bounded_reads_and_only_recommendation_writes(): void
    {
        $user = $this->jobSeeker();
        $company = $this->company();
        for ($index = 0; $index < 5; $index++) {
            $this->job($company);
        }
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => fn (Request $request) => Http::response(
                $this->validRankResponse($request->data()),
            ),
        ]);
        $token = $this->tokenFor($user);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $writes = array_filter($queries, static fn (string $sql): bool => preg_match(
            '/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i',
            $sql,
        ) === 1);
        $this->assertCount(7, $writes);
        $writeSql = implode("\n", array_values($writes));
        $this->assertSame(1, preg_match_all('/personal_access_tokens/i', $writeSql));
        $this->assertSame(1, preg_match_all('/recommendation_runs/i', $writeSql));
        $this->assertSame(5, preg_match_all('/recommendation_items/i', $writeSql));
        $this->assertDoesNotMatchRegularExpression(
            '/\b(job_postings|job_applications|cache)\b/i',
            $writeSql,
        );
        $this->assertLessThanOrEqual(25, count($queries));
        Http::assertSentCount(1);
        $this->assertInstanceOf(
            RecommendationOrchestratorContract::class,
            app(RecommendationOrchestratorContract::class),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureMl(
        int $maxJobs = 500,
        int $maxResults = 100,
        array $overrides = [],
    ): void {
        config()->set('recommendation_ml', array_merge([
            'enabled' => true,
            'base_url' => self::ML_URL,
            'service_token' => self::ML_TOKEN,
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 10,
            'max_jobs_per_request' => $maxJobs,
            'max_results' => $maxResults,
            'api_contract_version' => 'recommendation-ranking-api-v1',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'feature_schema_version' => 'job-rec-features-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validRankResponse(array $payload): array
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
            'model_source_revision' => '6cd51f733d5197e0c3f6b7dfb3711c2860ffef71',
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'requested_limit' => $payload['limit'],
            'prediction_count' => count($predictions),
            'predictions' => $predictions,
            'explanation_note' => MlRankResponse::EXPLANATION_NOTE,
            'latency_ms' => 1.0,
        ];
    }

    private function jobSeeker(string $email = 'jobseeker@example.com'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
        ]);
        JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => 'Backend Engineer',
        ]);

        return $user->load('jobSeekerProfile');
    }

    private function employer(): User
    {
        $company = $this->company();
        $user = User::factory()->create([
            'role' => UserRole::EMPLOYER,
            'status' => 'active',
        ]);
        EmployerProfile::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return $user;
    }

    private function company(string $approvalStatus = 'approved'): Company
    {
        return Company::create([
            'name' => 'Company '.Str::uuid(),
            'approval_status' => $approvalStatus,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function job(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::create(array_merge([
            'company_id' => $company->id,
            'title' => 'Platform Engineer '.Str::random(6),
            'description' => 'Build reliable recruitment services.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'work_mode' => 'remote',
            'status' => 'open',
            'published_at' => now()->subDay(),
            'application_deadline' => null,
        ], $overrides));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken(Str::random(12))->plainTextToken;
    }
}

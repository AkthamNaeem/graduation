<?php

namespace Tests\Unit;

use App\Contracts\Recommendation\RecommendationEligibilityProviderContract;
use App\Contracts\Recommendation\RecommendationMlClientFactoryContract;
use App\Contracts\Recommendation\RecommendationMlRequestMapperContract;
use App\Contracts\RecommendationMl\RecommendationMlClientContract;
use App\Data\Recommendation\RecommendationEligibility;
use App\Data\Recommendation\RecommendationEngine;
use App\Data\Recommendation\RecommendationMlClientHandle;
use App\Data\RecommendationMl\MlCandidateProfessionalFacts;
use App\Data\RecommendationMl\MlClientConfiguration;
use App\Data\RecommendationMl\MlJobProfessionalFacts;
use App\Data\RecommendationMl\MlRankJob;
use App\Data\RecommendationMl\MlRankRequest;
use App\Data\RecommendationMl\MlRankResponse;
use App\Enums\UserRole;
use App\Exceptions\Recommendation\RecommendationMappingException;
use App\Exceptions\RecommendationMl\MlRecommendationAuthenticationException;
use App\Exceptions\RecommendationMl\MlRecommendationConfigurationException;
use App\Exceptions\RecommendationMl\MlRecommendationContractException;
use App\Exceptions\RecommendationMl\MlRecommendationTransportException;
use App\Exceptions\RecommendationMl\MlRecommendationUnavailableException;
use App\Exceptions\RecommendationMl\MlRecommendationValidationException;
use App\Models\Company;
use App\Models\Education;
use App\Models\Experience;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\CandidateExperienceCalculator;
use App\Services\EducationLevelNormalizer;
use App\Services\MatchingService;
use App\Services\Recommendation\RecommendationMlRequestMapper;
use App\Services\Recommendation\RecommendationOrchestrator;
use App\Support\Recommendation\MlRecommendationResourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecommendationOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
    }

    public function test_disabled_ml_never_resolves_client_and_uses_matching_once(): void
    {
        [$user, $eligibility] = $this->eligibility(1, persist: false);
        $factory = Mockery::mock(RecommendationMlClientFactoryContract::class);
        $factory->shouldNotReceive('make');
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldNotReceive('map');
        $matching = $this->matchingMock($user, $eligibility);

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            false,
        )->recommend($user, 10);

        $this->assertSame(RecommendationEngine::MATCHING_V2, $result->engine);
        $this->assertFalse($result->fallbackUsed);
        $this->assertSame('ML_DISABLED', $result->safeFallbackCode);
        $this->assertSame('2.0', $result->items[0]['matching_score_version']);
    }

    public function test_zero_eligible_jobs_skips_client_and_matching(): void
    {
        [$user, $eligibility] = $this->eligibility(0, persist: false);
        $factory = Mockery::mock(RecommendationMlClientFactoryContract::class);
        $factory->shouldNotReceive('make');
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldNotReceive('map');
        $matching = Mockery::mock(MatchingService::class);
        $matching->shouldNotReceive('recommendJobsForUser');

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 10);

        $this->assertSame([], $result->items);
        $this->assertSame(0, $result->candidateCount);
        $this->assertFalse($result->fallbackUsed);
    }

    public function test_candidate_pool_above_max_falls_back_without_rank_or_batching(): void
    {
        [$user, $eligibility] = $this->eligibility(2, persist: false);
        $client = Mockery::mock(RecommendationMlClientContract::class);
        $client->shouldNotReceive('rank');
        $factory = Mockery::mock(RecommendationMlClientFactoryContract::class);
        $factory->shouldReceive('make')->once()->andReturn(
            new RecommendationMlClientHandle($client, $this->configuration(maxJobs: 1)),
        );
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldNotReceive('map');
        $matching = $this->matchingMock($user, $eligibility, 2);

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 10);

        $this->assertSame('ML_CANDIDATE_LIMIT_EXCEEDED', $result->safeFallbackCode);
        $this->assertSame(2, $result->returnedCount);
        $this->assertTrue($result->fallbackUsed);
    }

    #[DataProvider('typedFailureProvider')]
    public function test_typed_ml_failures_call_matching_once(
        string $exceptionClass,
        string $expectedCode,
        string $internalCode,
    ): void {
        [$user, $eligibility] = $this->eligibility(1, persist: false);
        $request = $this->rankRequest($eligibility);
        $client = Mockery::mock(RecommendationMlClientContract::class);
        $client->shouldReceive('rank')->once()->andThrow(new $exceptionClass(
            internalCode: $internalCode,
            requestId: $request->requestId,
            httpStatus: 500,
            operation: 'rank',
            retryable: true,
        ));
        $factory = $this->factoryMock($client);
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldReceive('map')->once()->andReturn($request);
        $matching = $this->matchingMock($user, $eligibility);

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 10);

        $this->assertSame(RecommendationEngine::MATCHING_V2_FALLBACK, $result->engine);
        $this->assertSame($expectedCode, $result->safeFallbackCode);
        $this->assertTrue($result->fallbackUsed);
    }

    public static function typedFailureProvider(): array
    {
        return [
            'transport' => [
                MlRecommendationTransportException::class,
                'ML_TRANSPORT_FAILURE',
                'ML_TRANSPORT_FAILED',
            ],
            'authentication' => [
                MlRecommendationAuthenticationException::class,
                'ML_AUTHENTICATION_FAILURE',
                'ML_AUTHENTICATION_FAILED',
            ],
            'provider validation' => [
                MlRecommendationValidationException::class,
                'ML_PROVIDER_VALIDATION_FAILURE',
                'ML_SERVICE_VALIDATION_FAILED',
            ],
            'rate limit' => [
                MlRecommendationUnavailableException::class,
                'ML_RATE_LIMITED',
                'ML_SERVICE_RATE_LIMITED',
            ],
            'unavailable' => [
                MlRecommendationUnavailableException::class,
                'ML_MODEL_UNAVAILABLE',
                'ML_SERVICE_UNAVAILABLE',
            ],
            'contract' => [
                MlRecommendationContractException::class,
                'ML_CONTRACT_FAILURE',
                'ML_RESPONSE_CONTRACT_INVALID',
            ],
        ];
    }

    public function test_configuration_failure_falls_back_without_mapping_or_rank(): void
    {
        [$user, $eligibility] = $this->eligibility(1, persist: false);
        $factory = Mockery::mock(RecommendationMlClientFactoryContract::class);
        $factory->shouldReceive('make')->once()->andThrow(
            new MlRecommendationConfigurationException(
                internalCode: 'ML_CONFIGURATION_MISSING',
            ),
        );
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldNotReceive('map');
        $matching = $this->matchingMock($user, $eligibility);

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 10);

        $this->assertSame('ML_CONFIG_INVALID', $result->safeFallbackCode);
    }

    public function test_mapper_failure_falls_back_without_rank(): void
    {
        [$user, $eligibility] = $this->eligibility(1, persist: false);
        $client = Mockery::mock(RecommendationMlClientContract::class);
        $client->shouldNotReceive('rank');
        $factory = $this->factoryMock($client);
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldReceive('map')->once()->andThrow(new RecommendationMappingException);
        $matching = $this->matchingMock($user, $eligibility);

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 10);

        $this->assertSame('ML_MAPPING_FAILURE', $result->safeFallbackCode);
    }

    public function test_local_missing_job_reconciliation_falls_back_without_partial_results(): void
    {
        [$user, $eligibility] = $this->eligibility(1, persist: false);
        $request = $this->rankRequest($eligibility);
        $response = $this->rankResponse($request, [1.0]);
        $client = Mockery::mock(RecommendationMlClientContract::class);
        $client->shouldReceive('rank')->once()->andReturn($response);
        $factory = $this->factoryMock($client);
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldReceive('map')->once()->andReturn($request);
        $matching = $this->matchingMock($user, $eligibility);

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 10);

        $this->assertSame('ML_RECONCILIATION_FAILURE', $result->safeFallbackCode);
        $this->assertSame(1, $result->returnedCount);
    }

    public function test_ml_success_reconciles_then_uses_laravel_ties_null_last_and_final_limit(): void
    {
        [$user, $eligibility] = $this->eligibility(5, persist: true);
        $jobs = $eligibility->jobs;
        $jobs[0]->update(['published_at' => now()->subDays(3)]);
        $jobs[1]->update(['published_at' => now()->subDays(2)]);
        $tieTime = now()->subDay()->startOfSecond();
        $jobs[2]->update(['published_at' => $tieTime]);
        $jobs[3]->update(['published_at' => $tieTime]);
        $jobs[4]->update(['published_at' => null]);
        foreach ($jobs as $index => $job) {
            $jobs[$index] = $job->fresh()->load(['company', 'skills']);
        }
        $eligibility = new RecommendationEligibility(
            $eligibility->profile,
            $jobs,
            $eligibility->now,
        );

        $request = $this->rankRequest($eligibility, limit: 4);
        $response = $this->rankResponse($request, [2.0, 1.0, 1.0, 1.0, 1.0]);
        $client = Mockery::mock(RecommendationMlClientContract::class);
        $client->shouldReceive('rank')->once()->with($request)->andReturn($response);
        $factory = $this->factoryMock($client);
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $mapper->shouldReceive('map')->once()->andReturn($request);
        $matching = Mockery::mock(MatchingService::class);
        $matching->shouldNotReceive('recommendJobsForUser');

        $result = $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 4);

        $this->assertSame([
            $jobs[0]->id,
            $jobs[2]->id,
            $jobs[3]->id,
            $jobs[1]->id,
        ], array_column(array_map(
            static fn (array $item): array => [
                'id' => $item['job']->id,
            ],
            $result->items,
        ), 'id'));
        $this->assertSame([1, 2, 3, 4], array_column($result->items, 'rank'));
        $this->assertSame(4, $result->returnedCount);
        $this->assertSame(RecommendationEngine::ML_XGBRANKER, $result->engine);
        $this->assertFalse($result->fallbackUsed);
        $this->assertArrayNotHasKey('_ml_raw_score', $result->items[0]);
    }

    public function test_fallback_failure_is_not_swallowed_or_retried(): void
    {
        [$user, $eligibility] = $this->eligibility(1, persist: false);
        $factory = Mockery::mock(RecommendationMlClientFactoryContract::class);
        $factory->shouldReceive('make')->once()->andThrow(
            new MlRecommendationConfigurationException('ML_CONFIGURATION_MISSING'),
        );
        $mapper = Mockery::mock(RecommendationMlRequestMapperContract::class);
        $matching = Mockery::mock(MatchingService::class);
        $matching->shouldReceive('recommendJobsForUser')->once()->andThrow(
            new \RuntimeException('matching failure'),
        );

        $this->expectExceptionMessage('matching failure');
        $this->orchestrator(
            $eligibility,
            $factory,
            $mapper,
            $matching,
            true,
        )->recommend($user, 10);
    }

    public function test_mapper_builds_only_allowed_normalized_professional_facts_without_queries(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::JOB_SEEKER,
            'name' => 'Private Candidate Name',
            'email' => 'private-candidate@example.com',
        ]);
        $profile = JobSeekerProfile::create([
            'user_id' => $user->id,
            'headline' => 'Backend Engineer',
            'summary' => 'Private raw CV summary that must not be sent.',
            'phone' => '+963 999 000 000',
        ]);
        Experience::create([
            'job_seeker_profile_id' => $profile->id,
            'title' => 'Private Employer Role',
            'company_name' => 'Private Employer Name',
            'start_date' => '2024-07-25',
            'end_date' => null,
            'is_current' => true,
            'description' => 'Private experience description.',
        ]);
        Education::create([
            'job_seeker_profile_id' => $profile->id,
            'institution' => 'Private University Name',
            'degree' => 'Master of Science',
            'description' => 'Private education description.',
        ]);
        $skills = collect([
            Skill::create(['name' => 'Laravel', 'slug' => 'laravel-one']),
            Skill::create(['name' => ' LARAVEL ', 'slug' => 'laravel-two']),
            Skill::create(['name' => 'MySQL', 'slug' => 'mysql']),
        ]);
        $profile->skills()->attach($skills->pluck('id'));

        $company = Company::create([
            'name' => 'Mapper Company',
            'approval_status' => 'approved',
        ]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Backend Engineer',
            'department' => 'Platform',
            'description' => 'Build reliable backend systems.',
            'responsibilities' => "Build APIs\nReview code",
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'education_level' => 'bachelor',
            'work_mode' => 'on_site',
            'status' => 'open',
        ]);
        $job->skills()->attach([
            $skills[0]->id => ['requirement_type' => 'required', 'weight' => 3],
            $skills[1]->id => ['requirement_type' => 'required', 'weight' => 5],
            $skills[2]->id => ['requirement_type' => 'nice_to_have', 'weight' => 2],
        ]);

        $loadedProfile = $profile->fresh()->load(['skills', 'experiences', 'education']);
        $loadedJob = $job->fresh()->load(['company', 'skills']);
        $eligibility = new RecommendationEligibility(
            $loadedProfile,
            [$loadedJob],
            Carbon::parse('2026-07-25 00:00:00'),
        );
        $mapper = $this->mapper();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $request = $mapper->map($eligibility, 10, $this->configuration());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $payload = $request->toArray();
        $this->assertCount(0, $queries);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $payload['request_id'],
        );
        $this->assertSame('job-rec-features-v1', $payload['feature_schema_version']);
        $this->assertNull($payload['candidate']['profile_ref']);
        $this->assertSame([
            'primary_domain',
            'adjacent_domains',
            'headline',
            'career_level',
            'total_experience_years',
            'education_level',
            'skills',
            'preferred_work_modes',
            'preferred_employment_types',
        ], array_keys($payload['candidate']['professional_facts']));
        $this->assertSame('Backend Engineer', $payload['candidate']['professional_facts']['headline']);
        $this->assertSame(2.0, $payload['candidate']['professional_facts']['total_experience_years']);
        $this->assertSame('master', $payload['candidate']['professional_facts']['education_level']);
        $this->assertSame([
            ['name' => 'laravel', 'proficiency' => null, 'years_experience' => null],
            ['name' => 'mysql', 'proficiency' => null, 'years_experience' => null],
        ], $payload['candidate']['professional_facts']['skills']);
        $this->assertSame([
            'domain',
            'title',
            'department',
            'description',
            'responsibilities',
            'required_skills',
            'nice_to_have_skills',
            'minimum_experience_years',
            'education_level',
            'career_level',
            'work_mode',
            'employment_type',
        ], array_keys($payload['jobs'][0]['professional_facts']));
        $this->assertSame(['Build APIs', 'Review code'], $payload['jobs'][0]['professional_facts']['responsibilities']);
        $this->assertSame([
            ['name' => 'laravel', 'weight' => 5.0],
        ], $payload['jobs'][0]['professional_facts']['required_skills']);
        $this->assertSame(['mysql'], $payload['jobs'][0]['professional_facts']['nice_to_have_skills']);
        $this->assertSame(3.0, $payload['jobs'][0]['professional_facts']['minimum_experience_years']);
        $this->assertSame('mid', $payload['jobs'][0]['professional_facts']['career_level']);
        $this->assertSame('onsite', $payload['jobs'][0]['professional_facts']['work_mode']);
        $this->assertSame('full_time', $payload['jobs'][0]['professional_facts']['employment_type']);
        $this->assertSame(1, $payload['limit']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach ([
            'private-candidate@example.com',
            '+963 999 000 000',
            'Private Candidate Name',
            'Private raw CV summary',
            'Private Employer Name',
            'Private experience description',
            'Private University Name',
            'Private education description',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $encoded);
        }
        foreach ([
            'email',
            'phone',
            'name',
            'raw_cv',
            'parsed_cv',
            'application',
            'test_results',
            'interview_results',
            'internal_notes',
            'sanctum_token',
            'password',
            'secret',
            'feature_vector',
            'label',
        ] as $forbiddenKey) {
            if ($forbiddenKey === 'name') {
                continue;
            }
            $this->assertStringNotContainsString('"'.$forbiddenKey.'"', $encoded);
        }
    }

    public function test_mapper_represents_missing_facts_as_null_or_empty_values(): void
    {
        [$user, $eligibility] = $this->eligibility(1, persist: true);
        $request = $this->mapper()->map($eligibility, 50, $this->configuration());
        $payload = $request->toArray();
        $candidate = $payload['candidate']['professional_facts'];
        $job = $payload['jobs'][0]['professional_facts'];

        $this->assertNull($candidate['headline']);
        $this->assertNull($candidate['primary_domain']);
        $this->assertNull($candidate['career_level']);
        $this->assertSame(0.0, $candidate['total_experience_years']);
        $this->assertNull($candidate['education_level']);
        $this->assertSame([], $candidate['skills']);
        $this->assertNull($job['domain']);
        $this->assertNull($job['department']);
        $this->assertSame([], $job['responsibilities']);
        $this->assertSame([], $job['required_skills']);
        $this->assertSame([], $job['nice_to_have_skills']);
    }

    public function test_mapper_rejects_unrepresentable_local_values_with_safe_exception(): void
    {
        [, $eligibility] = $this->eligibility(1, persist: true);
        $job = $eligibility->jobs[0];
        $job->description = str_repeat('x', 4001);

        $this->expectException(RecommendationMappingException::class);
        $this->expectExceptionMessage('ML_MAPPING_FAILURE');
        $this->mapper()->map($eligibility, 10, $this->configuration());
    }

    private function orchestrator(
        RecommendationEligibility $eligibility,
        RecommendationMlClientFactoryContract $factory,
        RecommendationMlRequestMapperContract $mapper,
        MatchingService $matching,
        bool $enabled,
    ): RecommendationOrchestrator {
        $provider = Mockery::mock(RecommendationEligibilityProviderContract::class);
        $provider->shouldReceive('eligibleJobs')->once()->andReturn($eligibility);

        return new RecommendationOrchestrator(
            $provider,
            $factory,
            $mapper,
            $matching,
            new MlRecommendationResourceAdapter,
            $enabled,
        );
    }

    /**
     * @return array{User, RecommendationEligibility}
     */
    private function eligibility(int $count, bool $persist): array
    {
        $user = $persist
            ? User::factory()->create(['role' => UserRole::JOB_SEEKER])
            : new User(['role' => UserRole::JOB_SEEKER]);
        $profile = $persist
            ? JobSeekerProfile::create(['user_id' => $user->id])
            : new JobSeekerProfile(['user_id' => 1]);
        $profile->id = $profile->id ?: 1;
        $profile->setRelation('skills', collect());
        $profile->setRelation('experiences', collect());
        $profile->setRelation('education', collect());

        $company = $persist
            ? Company::create(['name' => 'Orchestrator Co.', 'approval_status' => 'approved'])
            : new Company(['name' => 'Orchestrator Co.', 'approval_status' => 'approved']);
        $jobs = [];
        for ($index = 1; $index <= $count; $index++) {
            $job = $persist
                ? JobPosting::create([
                    'company_id' => $company->id,
                    'title' => 'Job '.$index,
                    'description' => 'Professional role '.$index,
                    'employment_type' => 'full-time',
                    'experience_level' => 'mid-level',
                    'status' => 'open',
                ])
                : new JobPosting([
                    'company_id' => 1,
                    'title' => 'Job '.$index,
                    'description' => 'Professional role '.$index,
                    'employment_type' => 'full-time',
                    'experience_level' => 'mid-level',
                    'status' => 'open',
                ]);
            if (! $persist) {
                $job->id = $index;
            }
            $job->setRelation('company', $company);
            $job->setRelation('skills', collect());
            $jobs[] = $job;
        }

        return [$user, new RecommendationEligibility($profile, $jobs, Carbon::parse('2026-07-25 12:00:00'))];
    }

    private function factoryMock(
        RecommendationMlClientContract $client,
    ): RecommendationMlClientFactoryContract {
        $factory = Mockery::mock(RecommendationMlClientFactoryContract::class);
        $factory->shouldReceive('make')->once()->andReturn(
            new RecommendationMlClientHandle($client, $this->configuration()),
        );

        return $factory;
    }

    private function matchingMock(
        User $user,
        RecommendationEligibility $eligibility,
        ?int $expectedItems = 1,
    ): MatchingService {
        $items = collect(array_map(
            fn (JobPosting $job): array => $this->matchingItem($job),
            $eligibility->jobs,
        ));
        $matching = Mockery::mock(MatchingService::class);
        $matching->shouldReceive('recommendJobsForUser')
            ->once()
            ->with($user, PHP_INT_MAX)
            ->andReturn($items);
        if ($expectedItems !== null) {
            $this->assertCount($expectedItems, $items);
        }

        return $matching;
    }

    /**
     * @return array<string, mixed>
     */
    private function matchingItem(JobPosting $job): array
    {
        return [
            'job' => $job,
            'score' => 50.0,
            'matching_score_version' => '2.0',
            'breakdown' => [],
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
            'reasons' => [],
        ];
    }

    private function rankRequest(
        RecommendationEligibility $eligibility,
        int $limit = 1,
    ): MlRankRequest {
        return new MlRankRequest(
            requestId: '00000000-0000-4000-8000-000000000014',
            featureSchemaVersion: 'job-rec-features-v1',
            candidateProfessionalFacts: new MlCandidateProfessionalFacts,
            jobs: array_map(
                static fn (JobPosting $job): MlRankJob => new MlRankJob(
                    (int) $job->id,
                    new MlJobProfessionalFacts,
                ),
                $eligibility->jobs,
            ),
            limit: min($limit, count($eligibility->jobs)),
        );
    }

    /**
     * @param  list<float>  $scores
     */
    private function rankResponse(MlRankRequest $request, array $scores): MlRankResponse
    {
        $predictions = [];
        foreach ($request->jobIds() as $index => $jobId) {
            $predictions[] = [
                'job_id' => $jobId,
                'rank' => $index + 1,
                'raw_score' => $scores[$index],
                'display_score' => 75.0 - $index,
                'top_positive_factors' => [],
                'top_negative_factors' => [],
            ];
        }

        return MlRankResponse::fromArray([
            'request_id' => $request->requestId,
            'api_contract_version' => 'recommendation-ranking-api-v1',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'dataset_version' => 'synthetic-job-rec-1.0.0',
            'feature_schema_version' => 'job-rec-features-v1',
            'model_source_revision' => '6cd51f733d5197e0c3f6b7dfb3711c2860ffef71',
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'requested_limit' => $request->limit,
            'prediction_count' => count($predictions),
            'predictions' => $predictions,
            'explanation_note' => MlRankResponse::EXPLANATION_NOTE,
            'latency_ms' => 1.0,
        ], $request, $this->configuration());
    }

    private function configuration(int $maxJobs = 500): MlClientConfiguration
    {
        return MlClientConfiguration::fromArray([
            'base_url' => 'http://ml.internal:8100',
            'service_token' => str_repeat('x', 32),
            'connect_timeout_seconds' => 2,
            'timeout_seconds' => 10,
            'max_jobs_per_request' => $maxJobs,
            'max_results' => min(100, $maxJobs),
            'api_contract_version' => 'recommendation-ranking-api-v1',
            'bundle_version' => 'job-rec-inference-bundle-v1',
            'model_version' => 'xgbranker-tuned-v1',
            'feature_schema_version' => 'job-rec-features-v1',
            'explanation_contract_version' => 'recommendation-explanation-contract-v1',
            'score_transform_version' => 'validation-minmax-selected-trial-t06-v1',
        ]);
    }

    private function mapper(): RecommendationMlRequestMapper
    {
        return new RecommendationMlRequestMapper(
            new CandidateExperienceCalculator,
            new EducationLevelNormalizer,
            config('matching.experience_levels'),
        );
    }
}

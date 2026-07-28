<?php

namespace Tests\Feature;

use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Contracts\Recommendation\RecommendationResultCacheContract;
use App\Contracts\Recommendation\RecommendationResultStoreContract;
use App\Data\Recommendation\RecommendationEngine;
use App\Models\ApplicationStatus;
use App\Models\JobApplication;
use App\Models\RecommendationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Feature\Concerns\BuildsRecommendationPersistenceScenarios;
use Tests\TestCase;

class RecommendationCacheTest extends TestCase
{
    use BuildsRecommendationPersistenceScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecommendationPersistenceScenario();
    }

    protected function tearDown(): void
    {
        $this->tearDownRecommendationPersistenceScenario();
        parent::tearDown();
    }

    public function test_recommendation_cache_first_computes_second_hits_and_flush_uses_database(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        $orchestrator = app(RecommendationOrchestratorContract::class);

        $computed = $orchestrator->recommend($user, 10);
        $cached = $orchestrator->recommend($user, 10);

        $this->assertFalse($computed->cacheHit);
        $this->assertFalse($computed->persistenceHit);
        $this->assertTrue($cached->cacheHit);
        $this->assertFalse($cached->persistenceHit);
        $this->assertEquals($computed->items, $cached->items);
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 1);
        Http::assertSentCount(1);

        Cache::flush();
        $databaseHit = $orchestrator->recommend($user, 10);
        $this->assertFalse($databaseHit->cacheHit);
        $this->assertTrue($databaseHit->persistenceHit);
        $this->assertEquals($computed->items, $databaseHit->items);
        $this->assertDatabaseCount('recommendation_runs', 1);
        Http::assertSentCount(1);

        $eligibility = $this->recommendationEligibility($user);
        $context = $this->recommendationContext($eligibility);
        $cache = app(RecommendationResultCacheContract::class);
        $pointer = Cache::get($cache->key($user->jobSeekerProfile->id, $context, 10));
        $this->assertSame([
            'schema_version',
            'recommendation_run_id',
            'context_hash',
            'requested_limit',
            'expires_at',
        ], array_keys($pointer));
        $this->assertCount(5, $pointer);
        $this->assertStringNotContainsString(
            self::ML_TOKEN,
            json_encode($pointer, JSON_THROW_ON_ERROR),
        );
    }

    public function test_recommendation_cache_uses_success_ttl(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        app(RecommendationOrchestratorContract::class)->recommend($user, 10);
        $success = RecommendationRun::latest('id')->firstOrFail();
        $this->assertSame(RecommendationEngine::ML_XGBRANKER, $success->engine);
        $this->assertEquals(
            900,
            $success->generated_at->diffInSeconds($success->expires_at),
        );
    }

    public function test_recommendation_cache_uses_fallback_ttl(): void
    {
        $fallbackUser = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        Http::fake([
            self::ML_URL.'/v1/recommendations/rank' => Http::response([], 503),
        ]);
        app(RecommendationOrchestratorContract::class)->recommend($fallbackUser, 10);
        $fallback = RecommendationRun::latest('id')->firstOrFail();
        $this->assertSame(
            RecommendationEngine::MATCHING_V2_FALLBACK,
            $fallback->engine,
        );
        $this->assertEquals(
            60,
            $fallback->generated_at->diffInSeconds($fallback->expires_at),
        );
        Http::assertSentCount(1);
    }

    public function test_recommendation_cache_uses_empty_ttl(): void
    {
        $emptyUser = $this->recommendationUser();
        Http::fake();
        $empty = app(RecommendationOrchestratorContract::class)
            ->recommend($emptyUser, 10);
        $emptyRun = RecommendationRun::latest('id')->firstOrFail();
        $this->assertSame([], $empty->items);
        $this->assertEquals(
            60,
            $emptyRun->generated_at->diffInSeconds($emptyRun->expires_at),
        );
        Http::assertNothingSent();
    }

    public function test_recommendation_cache_expired_cache_and_database_recompute_once(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        $orchestrator = app(RecommendationOrchestratorContract::class);
        $orchestrator->recommend($user, 10);
        RecommendationRun::firstOrFail()->update(['expires_at' => now()->subSecond()]);
        Cache::flush();

        $recomputed = $orchestrator->recommend($user, 10);

        $this->assertFalse($recomputed->cacheHit);
        $this->assertFalse($recomputed->persistenceHit);
        $this->assertDatabaseCount('recommendation_runs', 2);
        Http::assertSentCount(2);
    }

    public function test_recommendation_cache_corrupt_missing_and_invalid_runs_are_ignored(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        $eligibility = $this->recommendationEligibility($user);
        $context = $this->recommendationContext($eligibility);
        $cache = app(RecommendationResultCacheContract::class);
        $key = $cache->key($user->jobSeekerProfile->id, $context, 10);
        Cache::put($key, ['corrupt' => 'pointer'], 900);

        $orchestrator = app(RecommendationOrchestratorContract::class);
        $orchestrator->recommend($user, 10);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('recommendation_runs', 1);

        RecommendationRun::firstOrFail()->items()->update(['score' => 101]);
        Cache::flush();
        $orchestrator->recommend($user, 10);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('recommendation_runs', 2);

        Cache::flush();
        RecommendationRun::query()->update(['expires_at' => now()->subSecond()]);
        Cache::put($key, [
            'schema_version' => 'recommendation-cache-pointer-v1',
            'recommendation_run_id' => 999999,
            'context_hash' => $context->hash,
            'requested_limit' => 10,
            'expires_at' => now()->addMinute()->toISOString(),
        ], 60);
        $orchestrator->recommend($user, 10);
        Http::assertSentCount(3);
        $this->assertDatabaseCount('recommendation_runs', 3);
    }

    public function test_recommendation_cache_keys_separate_limits_and_profiles(): void
    {
        $first = $this->recommendationUser();
        $second = $this->recommendationUser(
            [],
            ['email' => 'second-profile@example.test'],
        );
        $company = $this->recommendationCompany();
        $this->recommendationJob($company);
        $this->recommendationJob($company);
        $this->fakeSuccessfulRecommendationMl();
        $orchestrator = app(RecommendationOrchestratorContract::class);

        $orchestrator->recommend($first, 1);
        $orchestrator->recommend($first, 2);
        $orchestrator->recommend($second, 1);

        $this->assertDatabaseCount('recommendation_runs', 3);
        Http::assertSentCount(3);
        $keys = [];
        foreach ([[$first, 1], [$first, 2], [$second, 1]] as [$user, $limit]) {
            $eligibility = $this->recommendationEligibility($user);
            $context = $this->recommendationContext($eligibility);
            $keys[] = app(RecommendationResultCacheContract::class)->key(
                $user->jobSeekerProfile->id,
                $context,
                $limit,
            );
        }
        $this->assertCount(3, array_unique($keys));
    }

    public function test_recommendation_cache_failure_does_not_retry_or_fail(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        $cache = Mockery::mock(RecommendationResultCacheContract::class);
        $cache->shouldReceive('findRunId')->once()->andThrow(
            new \RuntimeException('Synthetic cache read failure.'),
        );
        $cache->shouldReceive('put')->once()->andThrow(
            new \RuntimeException('Synthetic cache write failure.'),
        );
        $this->app->instance(RecommendationResultCacheContract::class, $cache);

        $result = app(RecommendationOrchestratorContract::class)
            ->recommend($user, 10);
        $this->assertSame(RecommendationEngine::ML_XGBRANKER, $result->engine);
        $this->assertDatabaseCount('recommendation_runs', 1);
        Http::assertSentCount(1);
    }

    public function test_recommendation_persistence_failure_does_not_retry_or_fail(): void
    {
        $secondUser = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        $store = Mockery::mock(RecommendationResultStoreContract::class);
        $store->shouldReceive('findLatest')->once()->andThrow(
            new \RuntimeException('Synthetic persistence read failure.'),
        );
        $store->shouldReceive('persist')->once()->andThrow(
            new \RuntimeException('Synthetic persistence write failure.'),
        );
        $this->app->instance(RecommendationResultStoreContract::class, $store);

        $secondResult = app(RecommendationOrchestratorContract::class)
            ->recommend($secondUser, 10);
        $this->assertSame(
            RecommendationEngine::ML_XGBRANKER,
            $secondResult->engine,
        );
        $this->assertDatabaseCount('recommendation_runs', 0);
        Http::assertSentCount(1);
    }

    public function test_recommendation_cache_hit_has_bounded_queries_and_no_writes(): void
    {
        $user = $this->recommendationUser();
        for ($index = 0; $index < 5; $index++) {
            $this->recommendationJob($this->recommendationCompany());
        }
        $this->fakeSuccessfulRecommendationMl();
        $orchestrator = app(RecommendationOrchestratorContract::class);
        $orchestrator->recommend($user, 10);
        $queries = [];
        DB::listen(
            function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            },
        );

        $result = $orchestrator->recommend($user, 10);

        $writes = array_filter(
            $queries,
            static fn (string $sql): bool => preg_match(
                '/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i',
                $sql,
            ) === 1,
        );
        $this->assertTrue($result->cacheHit);
        $this->assertSame([], array_values($writes));
        $this->assertLessThanOrEqual(10, count($queries));
        Http::assertSentCount(1);
    }

    public function test_recommendation_cache_manual_sqlite_lifecycle_verification(): void
    {
        $user = $this->recommendationUser();
        $job = $this->recommendationJob($this->recommendationCompany());
        $this->fakeSuccessfulRecommendationMl();
        $orchestrator = app(RecommendationOrchestratorContract::class);

        $computed = $orchestrator->recommend($user, 10);
        $firstRun = RecommendationRun::firstOrFail();
        $firstItemId = $firstRun->items()->firstOrFail()->id;
        $eligibility = $this->recommendationEligibility($user);
        $context = $this->recommendationContext($eligibility);
        $cache = app(RecommendationResultCacheContract::class);
        $key = $cache->key($user->jobSeekerProfile->id, $context, 10);
        $this->assertNotNull(Cache::get($key));
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 1);

        $cached = $orchestrator->recommend($user, 10);
        $this->assertTrue($cached->cacheHit);
        $this->assertEquals($computed->items, $cached->items);
        $this->assertDatabaseCount('recommendation_runs', 1);
        Http::assertSentCount(1);

        Cache::flush();
        $database = $orchestrator->recommend($user, 10);
        $this->assertTrue($database->persistenceHit);
        $this->assertNotNull(Cache::get($key));
        $this->assertDatabaseCount('recommendation_runs', 1);
        Http::assertSentCount(1);

        $oldHash = $context->hash;
        $job->update(['description' => 'Mutated scoring description.']);
        $mutated = $orchestrator->recommend($user, 10);
        $newContext = $this->recommendationContext(
            $this->recommendationEligibility($user),
        );
        $this->assertNotSame($oldHash, $newContext->hash);
        $this->assertFalse($mutated->cacheHit);
        $this->assertDatabaseCount('recommendation_runs', 2);
        Http::assertSentCount(2);

        JobApplication::create([
            'job_posting_id' => $job->id,
            'job_seeker_profile_id' => $user->jobSeekerProfile->id,
            'application_status_id' => ApplicationStatus::firstOrFail()->id,
            'cover_letter' => null,
            'consent_to_share_profile' => true,
        ]);
        $excluded = $orchestrator->recommend($user, 10);
        $this->assertSame([], $excluded->items);
        $this->assertDatabaseCount('recommendation_runs', 3);
        Http::assertSentCount(2);

        $firstRun->update([
            'generated_at' => now()->subDays(31),
            'expires_at' => now()->subSecond(),
        ]);
        $this->artisan('recommendations:prune --dry-run')
            ->expectsOutput('deleted_runs=1')
            ->assertSuccessful();
        $this->assertDatabaseHas('recommendation_runs', ['id' => $firstRun->id]);
        $this->artisan('recommendations:prune')
            ->expectsOutput('deleted_runs=1')
            ->assertSuccessful();
        $this->assertDatabaseMissing('recommendation_runs', ['id' => $firstRun->id]);
        $this->assertDatabaseMissing('recommendation_items', ['id' => $firstItemId]);
        $this->assertDatabaseCount('recommendation_runs', 2);
    }
}

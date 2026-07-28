<?php

namespace Tests\Feature;

use App\Contracts\Recommendation\RecommendationResultStoreContract;
use App\Data\Recommendation\RecommendationEngine;
use App\Data\Recommendation\RecommendationPersistenceConfiguration;
use App\Data\Recommendation\RecommendationResult;
use App\Models\RecommendationItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsRecommendationPersistenceScenarios;
use Tests\TestCase;

class RecommendationPersistenceTest extends TestCase
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

    public function test_recommendation_persistence_schema_models_constraints_and_cascade(): void
    {
        $this->assertTrue(Schema::hasColumns('recommendation_runs', [
            'id',
            'job_seeker_profile_id',
            'request_id',
            'context_hash',
            'context_version',
            'requested_limit',
            'candidate_count',
            'returned_count',
            'engine',
            'fallback_used',
            'fallback_code',
            'model_version',
            'feature_schema_version',
            'explanation_contract_version',
            'generated_at',
            'expires_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('recommendation_items', [
            'id',
            'recommendation_run_id',
            'job_posting_id',
            'rank',
            'score',
            'raw_score',
            'matching_score_version',
            'breakdown',
            'reasons',
            'created_at',
        ]));

        $user = $this->recommendationUser();
        $job = $this->recommendationJob($this->recommendationCompany());
        $eligibility = $this->recommendationEligibility($user);
        $context = $this->recommendationContext($eligibility);
        $result = $this->storedResultFixture(
            $eligibility,
            RecommendationEngine::ML_XGBRANKER,
        );
        $run = app(RecommendationResultStoreContract::class)
            ->persist($eligibility, $context, $result);
        $item = $run->items->firstOrFail();
        $otherJob = $this->recommendationJob($this->recommendationCompany());

        $this->assertInstanceOf(RecommendationEngine::class, $run->engine);
        $this->assertIsArray($item->breakdown);
        $this->assertIsArray($item->reasons);
        $this->assertSame('91.2500', $item->score);
        $this->assertSame('3.1250000000', $item->raw_score);

        foreach ([
            ['job_posting_id' => $job->id, 'rank' => 2],
            ['job_posting_id' => $otherJob->id, 'rank' => 1],
        ] as $duplicate) {
            try {
                DB::table('recommendation_items')->insert([
                    'recommendation_run_id' => $run->id,
                    'job_posting_id' => $duplicate['job_posting_id'],
                    'rank' => $duplicate['rank'],
                    'score' => 10,
                    'raw_score' => null,
                    'matching_score_version' => '2.0',
                    'breakdown' => null,
                    'reasons' => '[]',
                    'created_at' => now(),
                ]);
                $this->fail('A recommendation item uniqueness constraint was bypassed.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }

        $run->delete();
        $this->assertDatabaseMissing('recommendation_items', ['id' => $item->id]);

        $secondRun = app(RecommendationResultStoreContract::class)
            ->persist($eligibility, $context, $result);
        $secondRunId = $secondRun->id;
        $user->jobSeekerProfile->delete();
        $this->assertDatabaseMissing('recommendation_runs', ['id' => $secondRunId]);
    }

    #[DataProvider('engineProvider')]
    public function test_recommendation_persistence_round_trips_each_engine(
        RecommendationEngine $engine,
    ): void {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $eligibility = $this->recommendationEligibility($user);
        $context = $this->recommendationContext($eligibility);
        $store = app(RecommendationResultStoreContract::class);
        $expected = $this->storedResultFixture($eligibility, $engine);

        $run = $store->persist($eligibility, $context, $expected);
        $stored = $store->findById($run->id, $eligibility, $context, 10);

        $this->assertNotNull($stored);
        $actual = $stored->result;
        $this->assertSame($engine, $actual->engine);
        $this->assertSame($expected->fallbackUsed, $actual->fallbackUsed);
        $this->assertSame($expected->safeFallbackCode, $actual->safeFallbackCode);
        $this->assertSame($expected->modelVersion, $actual->modelVersion);
        $this->assertSame(
            $expected->featureSchemaVersion,
            $actual->featureSchemaVersion,
        );
        $this->assertSame(
            $expected->explanationContractVersion,
            $actual->explanationContractVersion,
        );
        $this->assertSame([1], array_column($actual->items, 'rank'));
        $this->assertEquals(
            $expected->items[0]['breakdown'],
            $actual->items[0]['breakdown'],
        );
        $this->assertEquals(
            $expected->items[0]['reasons'],
            $actual->items[0]['reasons'],
        );
        if ($engine === RecommendationEngine::ML_XGBRANKER) {
            $this->assertSame(
                3.125,
                $actual->items[0]['_persistence_raw_score'],
            );
        } else {
            $this->assertArrayNotHasKey(
                '_persistence_raw_score',
                $actual->items[0],
            );
        }
    }

    public function test_recommendation_persistence_round_trips_empty_result(): void
    {
        $user = $this->recommendationUser();
        $eligibility = $this->recommendationEligibility($user);
        $context = $this->recommendationContext($eligibility);
        $result = new RecommendationResult(
            items: [],
            engine: RecommendationEngine::ML_XGBRANKER,
            requestedLimit: 10,
            candidateCount: 0,
            returnedCount: 0,
            fallbackUsed: false,
        );
        $store = app(RecommendationResultStoreContract::class);

        $run = $store->persist($eligibility, $context, $result);
        $stored = $store->findLatest($eligibility, $context, 10);

        $this->assertNotNull($stored);
        $this->assertSame($run->id, $stored->run->id);
        $this->assertSame([], $stored->result->items);
        $this->assertEquals(60, $run->generated_at->diffInSeconds($run->expires_at));
    }

    public function test_recommendation_persistence_is_atomic_and_rejects_invalid_results(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $this->recommendationJob($this->recommendationCompany());
        $eligibility = $this->recommendationEligibility($user);
        $context = $this->recommendationContext($eligibility);
        $result = $this->storedResultFixture(
            $eligibility,
            RecommendationEngine::ML_XGBRANKER,
        );
        $createdItems = 0;
        Event::listen(
            'eloquent.creating: '.RecommendationItem::class,
            function () use (&$createdItems): void {
                $createdItems++;
                if ($createdItems === 2) {
                    throw new \RuntimeException('Synthetic item write failure.');
                }
            },
        );

        try {
            app(RecommendationResultStoreContract::class)
                ->persist($eligibility, $context, $result);
            $this->fail('The synthetic transaction failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Synthetic item write failure.',
                $exception->getMessage(),
            );
        }
        $this->assertDatabaseCount('recommendation_runs', 0);
        $this->assertDatabaseCount('recommendation_items', 0);

        Event::forget('eloquent.creating: '.RecommendationItem::class);
        $invalidItems = $result->items;
        $invalidItems[1]['job'] = $invalidItems[0]['job'];
        $invalid = new RecommendationResult(
            items: $invalidItems,
            engine: $result->engine,
            requestedLimit: 10,
            candidateCount: 2,
            returnedCount: 2,
            fallbackUsed: false,
            modelVersion: $result->modelVersion,
            featureSchemaVersion: $result->featureSchemaVersion,
            explanationContractVersion: $result->explanationContractVersion,
        );
        $this->expectException(\InvalidArgumentException::class);
        app(RecommendationResultStoreContract::class)
            ->persist($eligibility, $context, $invalid);
    }

    public function test_recommendation_persistence_stores_only_identifiers_scores_and_safe_metadata(): void
    {
        $user = $this->recommendationUser(
            ['headline' => 'Confidential Professional Headline'],
            [
                'name' => 'Private Candidate Name',
                'email' => 'private-candidate@example.test',
                'password' => 'private-auth-material',
            ],
        );
        $job = $this->recommendationJob(
            $this->recommendationCompany(),
            [
                'title' => 'Confidential Job Title',
                'description' => 'Confidential job body.',
            ],
        );
        $eligibility = $this->recommendationEligibility($user);
        $result = $this->storedResultFixture(
            $eligibility,
            RecommendationEngine::ML_XGBRANKER,
        );
        $items = $result->items;
        $items[0]['reasons'][] = [
            'code' => 'MISSING_REQUIRED_SKILLS',
            'message' => 'Some required skills are missing.',
            'skills' => ['Secret Skill Name'],
        ];
        $result = new RecommendationResult(
            items: $items,
            engine: $result->engine,
            requestedLimit: $result->requestedLimit,
            candidateCount: $result->candidateCount,
            returnedCount: $result->returnedCount,
            fallbackUsed: $result->fallbackUsed,
            modelVersion: $result->modelVersion,
            featureSchemaVersion: $result->featureSchemaVersion,
            explanationContractVersion: $result->explanationContractVersion,
            requestId: $result->requestId,
        );
        app(RecommendationResultStoreContract::class)->persist(
            $eligibility,
            $this->recommendationContext($eligibility),
            $result,
        );

        $serialized = json_encode([
            DB::table('recommendation_runs')->get(),
            DB::table('recommendation_items')->get(),
        ], JSON_THROW_ON_ERROR);
        foreach ([
            $user->name,
            $user->email,
            'private-auth-material',
            $user->jobSeekerProfile->headline,
            $job->title,
            $job->description,
            'Secret Skill Name',
            self::ML_TOKEN,
            self::ML_URL,
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 1);
        $this->assertSame(
            ['code', 'message'],
            array_keys(RecommendationItem::firstOrFail()->reasons[1]),
        );
    }

    public function test_recommendation_persistence_rejects_inconsistent_engine_metadata(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $eligibility = $this->recommendationEligibility($user);
        $valid = $this->storedResultFixture(
            $eligibility,
            RecommendationEngine::ML_XGBRANKER,
        );
        $invalid = new RecommendationResult(
            items: $valid->items,
            engine: RecommendationEngine::ML_XGBRANKER,
            requestedLimit: 10,
            candidateCount: 1,
            returnedCount: 1,
            fallbackUsed: false,
        );

        try {
            app(RecommendationResultStoreContract::class)->persist(
                $eligibility,
                $this->recommendationContext($eligibility),
                $invalid,
            );
            $this->fail('Inconsistent recommendation engine metadata was accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('recommendation_runs', 0);
            $this->assertDatabaseCount('recommendation_items', 0);
        }
    }

    public function test_recommendation_prune_dry_run_and_delete_are_safe_and_cascade(): void
    {
        $user = $this->recommendationUser();
        $this->recommendationJob($this->recommendationCompany());
        $eligibility = $this->recommendationEligibility($user);
        $store = app(RecommendationResultStoreContract::class);
        $result = $this->storedResultFixture(
            $eligibility,
            RecommendationEngine::MATCHING_V2,
        );
        $expired = $store->persist(
            $eligibility,
            $this->recommendationContext($eligibility),
            $result,
        );
        $expired->update([
            'generated_at' => now()->subDays(31),
            'expires_at' => now()->subSecond(),
        ]);
        $active = $store->persist(
            $eligibility,
            $this->recommendationContext($eligibility),
            $result,
        );
        $expiredItemId = $expired->items->firstOrFail()->id;

        $this->artisan('recommendations:prune --dry-run')
            ->expectsOutput('deleted_runs=1')
            ->assertSuccessful();
        $this->assertDatabaseHas('recommendation_runs', ['id' => $expired->id]);

        $this->artisan('recommendations:prune')
            ->expectsOutput('deleted_runs=1')
            ->assertSuccessful();
        $this->assertDatabaseMissing('recommendation_runs', ['id' => $expired->id]);
        $this->assertDatabaseMissing('recommendation_items', ['id' => $expiredItemId]);
        $this->assertDatabaseHas('recommendation_runs', ['id' => $active->id]);
    }

    public function test_recommendation_persistence_configuration_validates_boolean_and_bounds(): void
    {
        $configuration = RecommendationPersistenceConfiguration::fromArray([
            'cache_enabled' => 'false',
            'cache_ttl_seconds' => 900,
            'fallback_cache_ttl_seconds' => 60,
            'empty_cache_ttl_seconds' => 60,
            'run_retention_days' => 30,
        ]);
        $this->assertFalse($configuration->cacheEnabled);

        foreach ([
            ['cache_enabled' => 'not-a-boolean'],
            ['cache_ttl_seconds' => 0],
            ['cache_ttl_seconds' => 86401],
            ['cache_ttl_seconds' => 60, 'fallback_cache_ttl_seconds' => 61],
            ['cache_ttl_seconds' => 60, 'empty_cache_ttl_seconds' => 61],
            ['run_retention_days' => 0],
            ['run_retention_days' => 366],
        ] as $invalid) {
            try {
                RecommendationPersistenceConfiguration::fromArray(array_merge([
                    'cache_enabled' => true,
                    'cache_ttl_seconds' => 900,
                    'fallback_cache_ttl_seconds' => 60,
                    'empty_cache_ttl_seconds' => 60,
                    'run_retention_days' => 30,
                ], $invalid));
                $this->fail('Invalid recommendation configuration was accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public static function engineProvider(): array
    {
        return [
            'ml' => [RecommendationEngine::ML_XGBRANKER],
            'matching disabled' => [RecommendationEngine::MATCHING_V2],
            'matching fallback' => [RecommendationEngine::MATCHING_V2_FALLBACK],
        ];
    }
}

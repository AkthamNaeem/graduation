<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Models\RecommendationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\BuildsRecommendationPersistenceScenarios;
use Tests\TestCase;

class RecommendationConcurrencyTest extends TestCase
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

    public function test_recommendation_warm_cache_ten_requests_are_identical_without_new_work(): void
    {
        $user = $this->recommendationUser();
        $company = $this->recommendationCompany();
        for ($index = 0; $index < 5; $index++) {
            $this->recommendationJob($company);
        }
        $this->fakeSuccessfulRecommendationMl();
        $token = $user->createToken('phase17-warm-concurrency')->plainTextToken;
        $warm = $this->withToken($token)
            ->getJson('/api/v1/jobs/recommended?limit=5')
            ->assertOk()
            ->json('data');

        for ($index = 0; $index < 10; $index++) {
            $response = $this->withToken($token)
                ->getJson('/api/v1/jobs/recommended?limit=5')
                ->assertOk();
            $this->assertSame($warm, $response->json('data'));
        }

        Http::assertSentCount(1);
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 5);
    }

    public function test_recommendation_repeated_cold_miss_results_are_complete_and_deterministic(): void
    {
        $user = $this->recommendationUser();
        $company = $this->recommendationCompany();
        for ($index = 0; $index < 5; $index++) {
            $this->recommendationJob($company, [
                'published_at' => $index === 4 ? null : now()->subHours($index),
            ]);
        }
        $this->fakeSuccessfulRecommendationMl();
        $orchestrator = app(RecommendationOrchestratorContract::class);
        $results = [];

        for ($index = 0; $index < 5; $index++) {
            Cache::flush();
            RecommendationRun::query()->delete();
            $result = $orchestrator->recommend($user, 5);
            $this->assertCount(5, $result->items);
            $this->assertSame(range(1, 5), array_column($result->items, 'rank'));
            $results[] = array_map(
                static fn (array $item): array => [
                    'job_id' => (int) $item['job']->id,
                    'score' => (float) $item['score'],
                    'rank' => $item['rank'],
                    'engine' => $item['recommendation_engine'],
                ],
                $result->items,
            );
            $this->assertDatabaseCount('recommendation_runs', 1);
            $this->assertDatabaseCount('recommendation_items', 5);
        }

        foreach (array_slice($results, 1) as $result) {
            $this->assertSame($results[0], $result);
        }
        Http::assertSentCount(5);
    }

    public function test_recommendation_cold_race_contract_allows_equivalent_runs_but_never_partial_data(): void
    {
        $user = $this->recommendationUser();
        $company = $this->recommendationCompany();
        for ($index = 0; $index < 3; $index++) {
            $this->recommendationJob($company);
        }
        $this->fakeSuccessfulRecommendationMl();

        $result = app(RecommendationOrchestratorContract::class)
            ->recommend($user, 3);

        $this->assertCount(3, $result->items);
        $this->assertSame([1, 2, 3], array_column($result->items, 'rank'));
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 3);
        $this->assertSame(0, RecommendationRun::query()
            ->where('returned_count', '<>', 3)
            ->count());
    }
}

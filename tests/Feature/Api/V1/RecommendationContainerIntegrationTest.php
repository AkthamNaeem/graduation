<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Database\Seeders\ApplicationStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecommendationContainerIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('RUN_ML_CONTAINER_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set RUN_ML_CONTAINER_INTEGRATION=1 for the Docker integration test.');
        }

        $token = getenv('ML_CONTAINER_INTEGRATION_TOKEN');
        $readyFile = getenv('ML_CONTAINER_INTEGRATION_READY_FILE');
        $continueFile = getenv('ML_CONTAINER_INTEGRATION_CONTINUE_FILE');

        if (! is_string($token) || strlen($token) < 32
            || ! is_string($readyFile) || $readyFile === ''
            || ! is_string($continueFile) || $continueFile === '') {
            $this->fail('The Docker integration token and synchronization files are required.');
        }

        $this->seed(ApplicationStatusSeeder::class);
        config()->set('recommendation_ml', [
            'enabled' => true,
            'base_url' => 'http://127.0.0.1:8100',
            'service_token' => $token,
            'connect_timeout_seconds' => 1,
            'timeout_seconds' => 10,
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
        ]);
    }

    public function test_public_endpoint_uses_container_cache_persistence_and_stopped_service_fallback(): void
    {
        $requestCount = 0;
        Event::listen(RequestSending::class, function () use (&$requestCount): void {
            $requestCount++;
        });

        $candidate = User::factory()->create([
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
            'email' => 'phase16-container@example.test',
        ]);
        $profile = JobSeekerProfile::create([
            'user_id' => $candidate->id,
            'headline' => 'Backend platform engineer',
            'summary' => 'Builds reliable Laravel APIs and distributed services.',
        ]);
        $company = Company::create([
            'name' => 'Phase 16 Container Company',
            'approval_status' => 'approved',
        ]);
        $job = JobPosting::create([
            'company_id' => $company->id,
            'title' => 'Senior Backend Engineer',
            'department' => 'Engineering',
            'description' => 'Build reliable Laravel APIs and distributed services.',
            'responsibilities' => 'Design and operate backend services.',
            'requirements' => 'Professional backend engineering experience.',
            'employment_type' => 'full-time',
            'experience_level' => 'mid-level',
            'education_level' => 'bachelor',
            'location' => 'Remote',
            'work_mode' => 'remote',
            'status' => 'open',
            'published_at' => now()->subDay(),
            'application_deadline' => null,
        ]);
        $accessToken = $candidate->createToken(Str::random(12))->plainTextToken;

        $first = $this->withToken($accessToken)
            ->getJson('/api/v1/jobs/recommended?limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.recommendation_engine', 'ml_xgbranker')
            ->assertJsonPath('data.0.model_version', 'xgbranker-tuned-v1')
            ->assertJsonPath('data.0.feature_schema_version', 'job-rec-features-v1')
            ->assertJsonPath('data.0.fallback_used', false);

        $this->assertSame(1, $requestCount);
        $this->assertDatabaseCount('recommendation_runs', 1);
        $this->assertDatabaseCount('recommendation_items', 1);

        $second = $this->withToken($accessToken)
            ->getJson('/api/v1/jobs/recommended?limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.recommendation_engine', 'ml_xgbranker');

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(1, $requestCount);
        $this->assertDatabaseCount('recommendation_runs', 1);

        $publicBodies = $first->getContent().$second->getContent();
        $this->assertStringNotContainsString($candidate->email, $publicBodies);
        $this->assertStringNotContainsString((string) config('recommendation_ml.service_token'), $publicBodies);

        $readyFile = (string) getenv('ML_CONTAINER_INTEGRATION_READY_FILE');
        $continueFile = (string) getenv('ML_CONTAINER_INTEGRATION_CONTINUE_FILE');
        $this->assertNotFalse(file_put_contents($readyFile, 'ready'));

        $deadline = microtime(true) + 60;
        while (! is_file($continueFile) && microtime(true) < $deadline) {
            usleep(100_000);
        }
        $this->assertFileExists($continueFile, 'The test coordinator did not stop the ML container.');

        $profile->update(['headline' => 'Changed context after container shutdown']);

        $fallback = $this->withToken($accessToken)
            ->getJson('/api/v1/jobs/recommended?limit=10')
            ->assertOk()
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.recommendation_engine', 'matching_v2_fallback')
            ->assertJsonPath('data.0.matching_score_version', '2.0')
            ->assertJsonPath('data.0.fallback_used', true)
            ->assertJsonMissingPath('data.0.safe_fallback_code');

        $this->assertSame(2, $requestCount);
        $this->assertDatabaseCount('recommendation_runs', 2);
        $this->assertDatabaseCount('recommendation_items', 2);
        $this->assertStringNotContainsString($candidate->email, $fallback->getContent());
        $this->assertStringNotContainsString(
            (string) config('recommendation_ml.service_token'),
            $fallback->getContent(),
        );
    }
}

<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Data\Recommendation\RecommendationEngine;
use App\Data\Recommendation\RecommendationResult;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\Home\HomeActionResolver;
use App\Services\Home\ProfileCompletenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class HomeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_guest_home_returns_only_public_sections_and_visible_jobs(): void
    {
        $approved = $this->company('approved', 'Approved');
        $pending = $this->company('pending', 'Pending');
        $latest = $this->job($approved, ['title' => 'Latest', 'published_at' => now()]);
        $this->job($approved, ['status' => 'draft', 'title' => 'Draft']);
        $this->job($approved, ['status' => 'closed', 'title' => 'Closed']);
        $this->job($approved, [
            'title' => 'Expired',
            'application_deadline' => now()->subSecond(),
        ]);
        $this->job($pending, ['title' => 'Unapproved']);

        $response = $this->getJson('/api/v1/home');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Home data retrieved successfully.')
            ->assertJsonPath('data.viewer.type', 'guest')
            ->assertJsonPath('data.viewer.is_authenticated', false)
            ->assertJsonPath('data.latest_jobs.0.id', $latest->id)
            ->assertJsonCount(1, 'data.latest_jobs')
            ->assertJsonCount(1, 'data.featured_companies')
            ->assertJsonCount(3, 'data.app_features')
            ->assertJsonStructure([
                'data' => [
                    'viewer',
                    'hero' => ['title', 'description', 'primary_action', 'secondary_action'],
                    'latest_jobs',
                    'featured_companies',
                    'app_features',
                ],
            ])
            ->assertJsonMissingPath('data.profile_completeness')
            ->assertJsonMissingPath('data.required_action')
            ->assertJsonMissingPath('data.recommended_jobs');
    }

    public function test_featured_companies_are_ranked_by_open_job_count_then_recency(): void
    {
        $one = $this->company('approved', 'One');
        $two = $this->company('approved', 'Two');
        $this->job($one, ['published_at' => now()]);
        $this->job($two, ['published_at' => now()->subDay()]);
        $this->job($two, ['published_at' => now()->subDays(2)]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.featured_companies.0.id', $two->id)
            ->assertJsonPath('data.featured_companies.0.open_jobs_count', 2)
            ->assertJsonPath('data.featured_companies.1.id', $one->id);
    }

    public function test_valid_job_seeker_token_returns_personalized_home(): void
    {
        $user = $this->jobSeeker();
        $this->emptyRecommendations();

        $this->withToken($this->token($user))
            ->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.viewer.type', 'job_seeker')
            ->assertJsonPath('data.viewer.is_authenticated', true)
            ->assertJsonPath('data.viewer.id', $user->id)
            ->assertJsonPath('data.profile_completeness.percentage', 0)
            ->assertJsonPath('data.required_action.type', 'profile_incomplete')
            ->assertJsonPath('data.meta.recommendations_available', true)
            ->assertJsonPath('data.meta.recommendations.source', 'matching_v2')
            ->assertJsonMissingPath('data.hero')
            ->assertJsonMissingPath('data.app_features');
    }

    public function test_invalid_bearer_token_returns_401_instead_of_guest_home(): void
    {
        $this->withToken('invalid-token')
            ->getJson('/api/v1/home')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'INVALID_AUTHORIZATION_TOKEN')
            ->assertJsonMissingPath('data.viewer');
    }

    public function test_expired_sanctum_token_returns_401(): void
    {
        $user = $this->jobSeeker();
        $token = $user->createToken(
            'expired-home-test',
            ['*'],
            now()->subMinute(),
        )->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/home')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_AUTHORIZATION_TOKEN');
    }

    public function test_non_bearer_authorization_header_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Basic invalid')
            ->getJson('/api/v1/home')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'INVALID_AUTHORIZATION_TOKEN');
    }

    public function test_employer_and_admin_tokens_are_forbidden(): void
    {
        foreach ([UserRole::EMPLOYER, UserRole::ADMIN] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'status' => 'active',
            ]);

            $this->withToken($this->token($user))
                ->getJson('/api/v1/home')
                ->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'Mobile Home is available to job seekers only.',
                );
        }
    }

    public function test_suspended_job_seeker_is_forbidden_by_existing_account_protection(): void
    {
        $user = $this->jobSeeker();
        $user->update(['status' => 'suspended']);

        $this->withToken($this->token($user))
            ->getJson('/api/v1/home')
            ->assertForbidden()
            ->assertJsonPath('code', 'USER_SUSPENDED');
    }

    public function test_home_reuses_orchestrator_with_a_six_item_limit(): void
    {
        $user = $this->jobSeeker();
        $company = $this->company();
        $visible = $this->job($company, ['title' => 'Visible']);
        $visible->load('company');

        $items = [
            $this->recommendationItem($visible, 91),
        ];
        $mock = Mockery::mock(RecommendationOrchestratorContract::class);
        $mock->shouldReceive('recommend')
            ->once()
            ->withArgs(fn (User $actual, int $limit): bool => $actual->is($user)
                && $limit === 6)
            ->andReturn($this->recommendationResult($items));
        $this->app->instance(RecommendationOrchestratorContract::class, $mock);

        $this->withToken($this->token($user))
            ->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(1, 'data.recommended_jobs')
            ->assertJsonPath('data.recommended_jobs.0.id', $visible->id)
            ->assertJsonPath('data.recommended_jobs.0.match.score', 91)
            ->assertJsonPath(
                'data.recommended_jobs.0.match.matched_skills.0',
                'Laravel',
            )
            ->assertJsonPath(
                'data.recommended_jobs.0.match.missing_skills.0',
                'Docker',
            );
    }

    public function test_unexpected_recommendation_failure_does_not_fail_home(): void
    {
        $user = $this->jobSeeker();
        $mock = Mockery::mock(RecommendationOrchestratorContract::class);
        $mock->shouldReceive('recommend')->once()->andThrow(new RuntimeException);
        $this->app->instance(RecommendationOrchestratorContract::class, $mock);

        $this->withToken($this->token($user))
            ->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(0, 'data.recommended_jobs')
            ->assertJsonPath('data.meta.recommendations_available', false)
            ->assertJsonPath('data.meta.recommendations.source', 'unavailable');
    }

    public function test_home_response_never_contains_cv_raw_or_parsed_payloads(): void
    {
        $user = $this->jobSeeker();
        $this->emptyRecommendations();

        $content = $this->withToken($this->token($user))
            ->getJson('/api/v1/home')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('raw_text', $content);
        $this->assertStringNotContainsString('parsed_json', $content);
    }

    public function test_no_required_action_is_serialized_as_null(): void
    {
        $user = $this->jobSeeker();
        $this->emptyRecommendations();
        $completeness = [
            'percentage' => 100,
            'is_complete' => true,
            'missing_items_count' => 0,
            'missing_items' => [],
            'next_item' => null,
        ];
        $profileCompleteness = Mockery::mock(ProfileCompletenessService::class);
        $profileCompleteness->shouldReceive('calculate')
            ->once()
            ->andReturn($completeness);
        $actionResolver = Mockery::mock(HomeActionResolver::class);
        $actionResolver->shouldReceive('resolve')
            ->once()
            ->withArgs(fn ($profile, array $actual): bool => $actual === $completeness)
            ->andReturnNull();
        $this->app->instance(
            ProfileCompletenessService::class,
            $profileCompleteness,
        );
        $this->app->instance(HomeActionResolver::class, $actionResolver);

        $this->withToken($this->token($user))
            ->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.required_action', null);
    }

    public function test_public_home_queries_are_bounded_as_rows_increase(): void
    {
        foreach (range(1, 8) as $index) {
            $company = $this->company('approved', 'Company '.$index);
            foreach (range(1, 3) as $jobIndex) {
                $this->job($company, [
                    'title' => "Job {$index}-{$jobIndex}",
                    'published_at' => now()->subMinutes($index + $jobIndex),
                ]);
            }
        }

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(5, 'data.latest_jobs')
            ->assertJsonCount(6, 'data.featured_companies');

        $this->assertLessThanOrEqual(5, count($queries));
    }

    public function test_home_route_uses_optional_authentication_outside_mandatory_auth_group(): void
    {
        $route = Route::getRoutes()->getByName('v1.home');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/home', $route->uri());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth.sanctum.optional', $middleware);
        $this->assertNotContains('auth:sanctum', $middleware);
    }

    private function emptyRecommendations(): void
    {
        $mock = Mockery::mock(RecommendationOrchestratorContract::class);
        $mock->shouldReceive('recommend')
            ->once()
            ->andReturn($this->recommendationResult([]));
        $this->app->instance(RecommendationOrchestratorContract::class, $mock);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function recommendationResult(array $items): RecommendationResult
    {
        return new RecommendationResult(
            items: $items,
            engine: RecommendationEngine::MATCHING_V2,
            requestedLimit: 6,
            candidateCount: count($items),
            returnedCount: count($items),
            fallbackUsed: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recommendationItem(JobPosting $job, int $score): array
    {
        return [
            'job' => $job,
            'score' => $score,
            'matched_skills' => ['Laravel'],
            'missing_required_skills' => [['id' => 1, 'name' => 'Docker']],
            'reasons' => [[
                'code' => 'SKILLS_MATCH',
                'message' => 'Most required skills are present.',
            ]],
        ];
    }

    private function jobSeeker(): User
    {
        $user = User::factory()->create([
            'role' => UserRole::JOB_SEEKER,
            'status' => 'active',
        ]);
        JobSeekerProfile::create(['user_id' => $user->id]);

        return $user;
    }

    private function token(User $user): string
    {
        return $user->createToken('home-test')->plainTextToken;
    }

    private function company(
        string $approvalStatus = 'approved',
        ?string $name = null,
    ): Company {
        return Company::factory()->create([
            'name' => $name ?? fake()->unique()->company(),
            'approval_status' => $approvalStatus,
            'industry' => 'Technology',
            'location' => 'Damascus',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function job(Company $company, array $overrides = []): JobPosting
    {
        return JobPosting::factory()->create(array_merge([
            'company_id' => $company->id,
            'status' => 'open',
            'published_at' => now()->subHour(),
            'application_deadline' => null,
        ], $overrides));
    }
}

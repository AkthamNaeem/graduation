<?php

namespace App\Providers;

use App\Contracts\CV\CVTextParser;
use App\Contracts\Recommendation\RecommendationContextFingerprintContract;
use App\Contracts\Recommendation\RecommendationEligibilityProviderContract;
use App\Contracts\Recommendation\RecommendationMlClientFactoryContract;
use App\Contracts\Recommendation\RecommendationMlRequestMapperContract;
use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Contracts\Recommendation\RecommendationResultCacheContract;
use App\Contracts\Recommendation\RecommendationResultHydratorContract;
use App\Contracts\Recommendation\RecommendationResultStoreContract;
use App\Contracts\RecommendationMl\RecommendationMlClientContract;
use App\Data\Recommendation\RecommendationPersistenceConfiguration;
use App\Enums\UserRole;
use App\Models\ApplicationInternalNote;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\Test;
use App\Models\TestAttempt;
use App\Policies\ApplicationInternalNotePolicy;
use App\Policies\ApplicationTestAssignmentPolicy;
use App\Policies\InterviewPolicy;
use App\Policies\JobApplicationPolicy;
use App\Policies\JobPostingPolicy;
use App\Policies\TestAttemptPolicy;
use App\Policies\TestPolicy;
use App\Services\CandidateExperienceCalculator;
use App\Services\CV\GroqCVTextParser;
use App\Services\CV\OpenAICVTextParser;
use App\Services\CV\RuleBasedCVTextParser;
use App\Services\EducationLevelNormalizer;
use App\Services\MatchingService;
use App\Services\Recommendation\RecommendationContextFingerprint;
use App\Services\Recommendation\RecommendationEligibilityProvider;
use App\Services\Recommendation\RecommendationMlClientFactory;
use App\Services\Recommendation\RecommendationMlRequestMapper;
use App\Services\Recommendation\RecommendationOrchestrator;
use App\Services\Recommendation\RecommendationResultCache;
use App\Services\Recommendation\RecommendationResultHydrator;
use App\Services\Recommendation\RecommendationResultStore;
use App\Services\RecommendationMl\RecommendationMlClient;
use App\Support\ApiResponse;
use App\Support\Recommendation\MlRecommendationResourceAdapter;
use App\Support\RecommendationMl\MlOutboundPayloadGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            RecommendationEligibilityProviderContract::class,
            RecommendationEligibilityProvider::class,
        );
        $this->app->bind(
            RecommendationMlClientFactoryContract::class,
            fn ($app): RecommendationMlClientFactoryContract => new RecommendationMlClientFactory(
                http: $app->make(Factory::class),
                payloadGuard: $app->make(MlOutboundPayloadGuard::class),
                configuration: (array) config('recommendation_ml', []),
            ),
        );
        $this->app->bind(
            RecommendationMlRequestMapperContract::class,
            fn ($app): RecommendationMlRequestMapperContract => new RecommendationMlRequestMapper(
                experienceCalculator: $app->make(CandidateExperienceCalculator::class),
                educationNormalizer: $app->make(EducationLevelNormalizer::class),
                experienceLevels: (array) config('matching.experience_levels', []),
            ),
        );
        $this->app->singleton(
            RecommendationPersistenceConfiguration::class,
            fn (): RecommendationPersistenceConfiguration => (
                RecommendationPersistenceConfiguration::fromArray(
                    (array) config('recommendation_ml.persistence', []),
                )
            ),
        );
        $this->app->bind(
            RecommendationContextFingerprintContract::class,
            fn ($app): RecommendationContextFingerprintContract => new RecommendationContextFingerprint(
                experienceCalculator: $app->make(CandidateExperienceCalculator::class),
                matchingConfiguration: (array) config('matching', []),
                mlConfiguration: (array) config('recommendation_ml', []),
                contextVersion: $app
                    ->make(RecommendationPersistenceConfiguration::class)
                    ->contextVersion,
                rankingPolicyVersion: $app
                    ->make(RecommendationPersistenceConfiguration::class)
                    ->rankingPolicyVersion,
            ),
        );
        $this->app->bind(
            RecommendationResultHydratorContract::class,
            fn (): RecommendationResultHydratorContract => new RecommendationResultHydrator(
                mlConfiguration: (array) config('recommendation_ml', []),
                matchingVersion: (string) config('matching.version', '2.0'),
            ),
        );
        $this->app->bind(
            RecommendationResultStoreContract::class,
            fn ($app): RecommendationResultStoreContract => new RecommendationResultStore(
                hydrator: $app->make(RecommendationResultHydratorContract::class),
                configuration: $app->make(RecommendationPersistenceConfiguration::class),
            ),
        );
        $this->app->bind(
            RecommendationResultCacheContract::class,
            fn ($app): RecommendationResultCacheContract => new RecommendationResultCache(
                cache: $app->make('cache.store'),
                configuration: $app->make(RecommendationPersistenceConfiguration::class),
            ),
        );
        $this->app->bind(
            RecommendationOrchestratorContract::class,
            fn ($app): RecommendationOrchestratorContract => new RecommendationOrchestrator(
                eligibilityProvider: $app->make(RecommendationEligibilityProviderContract::class),
                clientFactory: $app->make(RecommendationMlClientFactoryContract::class),
                requestMapper: $app->make(RecommendationMlRequestMapperContract::class),
                matchingService: $app->make(MatchingService::class),
                resourceAdapter: $app->make(MlRecommendationResourceAdapter::class),
                mlEnabled: (bool) config('recommendation_ml.enabled', false),
                contextFingerprint: $app->make(
                    RecommendationContextFingerprintContract::class,
                ),
                resultCache: $app->make(RecommendationResultCacheContract::class),
                resultStore: $app->make(RecommendationResultStoreContract::class),
                persistenceConfiguration: $app->make(
                    RecommendationPersistenceConfiguration::class,
                ),
            ),
        );

        $this->app->bind(
            RecommendationMlClientContract::class,
            fn ($app): RecommendationMlClientContract => new RecommendationMlClient(
                http: $app->make(Factory::class),
                configuration: (array) config('recommendation_ml', []),
                payloadGuard: $app->make(MlOutboundPayloadGuard::class),
            ),
        );

        $this->app->bind(CVTextParser::class, function ($app): CVTextParser {
            return match (config('cv.parser.driver', 'rules')) {
                'openai' => $app->make(OpenAICVTextParser::class),
                'groq' => $app->make(GroqCVTextParser::class),
                'rules' => $app->make(RuleBasedCVTextParser::class),
                default => throw new InvalidArgumentException(
                    'Invalid CV parser driver. Supported drivers: openai, groq, rules.'
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('email-verification-verify', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by($this->otpRateLimitKey($request))
                ->response(
                    fn (Request $request, array $headers) => ApiResponse::error(
                        message: 'Too many email verification attempts. Please try again later.',
                        status: 429,
                        code: 'OTP_RATE_LIMIT_EXCEEDED',
                    )->withHeaders($headers),
                );
        });

        RateLimiter::for('email-verification-resend', function (Request $request): Limit {
            return Limit::perMinutes(10, 3)
                ->by($this->otpRateLimitKey($request))
                ->response(
                    fn (Request $request, array $headers) => ApiResponse::error(
                        message: 'Too many verification code requests. Please try again later.',
                        status: 429,
                        code: 'OTP_RATE_LIMIT_EXCEEDED',
                    )->withHeaders($headers),
                );
        });

        RateLimiter::for('password-reset-forgot', function (Request $request): Limit {
            return Limit::perMinutes(10, 3)
                ->by($this->otpRateLimitKey($request))
                ->response(
                    fn (Request $request, array $headers) => ApiResponse::error(
                        message: 'Too many password reset requests. Please try again later.',
                        status: 429,
                        code: 'PASSWORD_RESET_RATE_LIMIT_EXCEEDED',
                    )->withHeaders($headers),
                );
        });

        RateLimiter::for('password-reset-reset', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by($this->otpRateLimitKey($request))
                ->response(
                    fn (Request $request, array $headers) => ApiResponse::error(
                        message: 'Too many password reset attempts. Please try again later.',
                        status: 429,
                        code: 'PASSWORD_RESET_RATE_LIMIT_EXCEEDED',
                    )->withHeaders($headers),
                );
        });

        Gate::policy(JobPosting::class, JobPostingPolicy::class);
        Gate::policy(JobApplication::class, JobApplicationPolicy::class);
        Gate::policy(ApplicationTestAssignment::class, ApplicationTestAssignmentPolicy::class);
        Gate::policy(ApplicationInternalNote::class, ApplicationInternalNotePolicy::class);
        Gate::policy(Interview::class, InterviewPolicy::class);
        Gate::policy(TestAttempt::class, TestAttemptPolicy::class);
        Gate::policy(Test::class, TestPolicy::class);
        Gate::before(
            fn ($user): ?bool => $user->role === UserRole::ADMIN ? true : null,
        );
        Gate::define('access-admin', fn ($user): bool => $user->role === UserRole::ADMIN);

        JsonResource::withoutWrapping();
    }

    private function otpRateLimitKey(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email')));

        return hash('sha256', $email.'|'.$request->ip());
    }
}

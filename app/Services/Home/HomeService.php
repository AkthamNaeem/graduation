<?php

namespace App\Services\Home;

use App\Contracts\Recommendation\RecommendationOrchestratorContract;
use App\Enums\UserRole;
use App\Http\Resources\Api\V1\Home\HomeActionResource;
use App\Http\Resources\Api\V1\Home\HomeCompanyResource;
use App\Http\Resources\Api\V1\Home\HomeJobResource;
use App\Models\Company;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\JobPostingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class HomeService
{
    private const LATEST_JOBS_LIMIT = 5;

    private const FEATURED_COMPANIES_LIMIT = 6;

    private const RECOMMENDATIONS_LIMIT = 6;

    public function __construct(
        private readonly JobPostingService $jobPostingService,
        private readonly RecommendationOrchestratorContract $recommendationOrchestrator,
        private readonly ProfileCompletenessService $profileCompletenessService,
        private readonly HomeActionResolver $homeActionResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(?User $user): array
    {
        return $user === null
            ? $this->guestHome()
            : $this->jobSeekerHome($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function guestHome(): array
    {
        return [
            'viewer' => [
                'type' => 'guest',
                'is_authenticated' => false,
            ],
            'hero' => [
                'title' => __('home.guest_title'),
                'description' => __('home.guest_subtitle'),
                'primary_action' => [
                    'type' => 'register',
                    'label' => __('home.register'),
                ],
                'secondary_action' => [
                    'type' => 'login',
                    'label' => __('home.login'),
                ],
            ],
            'latest_jobs' => HomeJobResource::collection(
                $this->latestJobs(),
            ),
            'featured_companies' => HomeCompanyResource::collection(
                $this->featuredCompanies(),
            ),
            'app_features' => $this->appFeatures(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobSeekerHome(User $user): array
    {
        if ($user->role !== UserRole::JOB_SEEKER) {
            throw new AuthorizationException(
                __('home.job_seeker_only'),
            );
        }

        $profile = $user->jobSeekerProfile()
            ->withCount(['experiences', 'education', 'skills'])
            ->with('primaryCVFile:id,user_id,confirmed_at,archived_at')
            ->firstOrFail();
        $profileCompleteness = $this->profileCompletenessService->calculate(
            $user,
            $profile,
        );
        [$recommendations, $recommendationMeta] = $this->recommendations(
            $user,
            $profile,
        );
        $requiredAction = $this->homeActionResolver->resolve(
            $profile,
            $profileCompleteness,
        );

        return [
            'viewer' => [
                'type' => 'job_seeker',
                'is_authenticated' => true,
                'id' => $user->id,
                'name' => $user->name,
                'avatar_url' => null,
            ],
            'profile_completeness' => $profileCompleteness,
            'required_action' => $requiredAction === null
                ? null
                : HomeActionResource::make($requiredAction),
            'recommended_jobs' => HomeJobResource::collection($recommendations),
            'featured_companies' => HomeCompanyResource::collection(
                $this->featuredCompanies(),
            ),
            'latest_jobs' => HomeJobResource::collection(
                $this->latestJobs(),
            ),
            'meta' => [
                'recommendations_available' => $recommendationMeta['available'],
                'recommendations' => $recommendationMeta,
            ],
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function recommendations(
        User $user,
        JobSeekerProfile $profile,
    ): array {
        try {
            $result = $this->recommendationOrchestrator->recommend(
                $user,
                self::RECOMMENDATIONS_LIMIT,
            );
            $items = array_slice($result->items, 0, self::RECOMMENDATIONS_LIMIT);

            return [
                $items,
                [
                    'available' => true,
                    'source' => $result->engine->value,
                    'fallback_used' => $result->fallbackUsed,
                ],
            ];
        } catch (Throwable $exception) {
            Log::warning('home_recommendations_unavailable', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            return [
                [],
                [
                    'available' => false,
                    'source' => 'unavailable',
                    'fallback_used' => false,
                ],
            ];
        }
    }

    private function latestJobs()
    {
        return $this->jobPostingService->getPublicJobs([
            'accepting_applications' => true,
            'sort_by' => 'published_at',
            'sort_direction' => 'desc',
            'per_page' => self::LATEST_JOBS_LIMIT,
        ])->getCollection();
    }

    private function featuredCompanies()
    {
        $openJobs = static fn (Builder $query): Builder => $query
            ->where('status', 'open')
            ->where(function (Builder $deadline): void {
                $deadline->whereNull('application_deadline')
                    ->orWhere('application_deadline', '>=', now());
            });

        return Company::query()
            ->select(['id', 'name', 'industry', 'location'])
            ->where('approval_status', 'approved')
            ->whereHas('jobPostings', $openJobs)
            ->withCount(['jobPostings as open_jobs_count' => $openJobs])
            ->withMax(
                ['jobPostings as latest_open_job_published_at' => $openJobs],
                'published_at',
            )
            ->orderByDesc('open_jobs_count')
            ->orderByDesc('latest_open_job_published_at')
            ->orderBy('id')
            ->limit(self::FEATURED_COMPANIES_LIMIT)
            ->get();
    }

    /**
     * @return list<array<string, string>>
     */
    private function appFeatures(): array
    {
        return [
            [
                'key' => 'smart_recommendations',
                'title' => __('home.features.recommendations_title'),
                'description' => __('home.features.recommendations_subtitle'),
            ],
            [
                'key' => 'application_tracking',
                'title' => __('home.features.tracking_title'),
                'description' => __('home.features.tracking_subtitle'),
            ],
            [
                'key' => 'cv_parsing',
                'title' => __('home.features.cv_title'),
                'description' => __('home.features.cv_subtitle'),
            ],
        ];
    }
}

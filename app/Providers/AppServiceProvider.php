<?php

namespace App\Providers;

use App\Contracts\CV\CVTextParser;
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
use App\Services\CV\OpenAICVTextParser;
use App\Services\CV\RuleBasedCVTextParser;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CVTextParser::class, function ($app): CVTextParser {
            return match (config('cv.parser.driver', 'rules')) {
                'openai' => $app->make(OpenAICVTextParser::class),
                'rules' => $app->make(RuleBasedCVTextParser::class),
                default => throw new InvalidArgumentException(
                    'Invalid CV parser driver. Supported drivers: openai, rules.'
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(JobPosting::class, JobPostingPolicy::class);
        Gate::policy(JobApplication::class, JobApplicationPolicy::class);
        Gate::policy(ApplicationTestAssignment::class, ApplicationTestAssignmentPolicy::class);
        Gate::policy(ApplicationInternalNote::class, ApplicationInternalNotePolicy::class);
        Gate::policy(Interview::class, InterviewPolicy::class);
        Gate::policy(TestAttempt::class, TestAttemptPolicy::class);
        Gate::policy(Test::class, TestPolicy::class);
        Gate::define('access-admin', fn ($user): bool => $user->role === UserRole::ADMIN);

        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            return rtrim((string) config('app.url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());
        });

        JsonResource::withoutWrapping();
    }
}

<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Events\ApplicationStatusChanged;
use App\Events\ApplicationSubmitted;
use App\Events\InterviewCancelled;
use App\Events\InterviewEvaluated;
use App\Events\InterviewScheduled;
use App\Events\InterviewUpdated;
use App\Events\TestAssigned;
use App\Events\TestEvaluated;
use App\Events\TestSubmitted;
use App\Listeners\CreateApplicationStatusChangedNotification;
use App\Listeners\CreateApplicationSubmittedNotifications;
use App\Listeners\CreateInterviewCancelledNotification;
use App\Listeners\CreateInterviewEvaluatedNotification;
use App\Listeners\CreateInterviewScheduledNotification;
use App\Listeners\CreateInterviewUpdatedNotification;
use App\Listeners\CreateTestAssignedNotification;
use App\Listeners\CreateTestEvaluatedNotification;
use App\Listeners\CreateTestSubmittedNotification;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Models\TestAttempt;
use App\Policies\ApplicationTestAssignmentPolicy;
use App\Policies\InterviewPolicy;
use App\Policies\JobApplicationPolicy;
use App\Policies\JobPostingPolicy;
use App\Policies\TestAttemptPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(JobPosting::class, JobPostingPolicy::class);
        Gate::policy(JobApplication::class, JobApplicationPolicy::class);
        Gate::policy(ApplicationTestAssignment::class, ApplicationTestAssignmentPolicy::class);
        Gate::policy(Interview::class, InterviewPolicy::class);
        Gate::policy(TestAttempt::class, TestAttemptPolicy::class);
        Gate::define('access-admin', fn ($user): bool => $user->role === UserRole::ADMIN);

        Event::listen(ApplicationStatusChanged::class, CreateApplicationStatusChangedNotification::class);
        Event::listen(ApplicationSubmitted::class, CreateApplicationSubmittedNotifications::class);
        Event::listen(TestAssigned::class, CreateTestAssignedNotification::class);
        Event::listen(TestSubmitted::class, CreateTestSubmittedNotification::class);
        Event::listen(TestEvaluated::class, CreateTestEvaluatedNotification::class);
        Event::listen(InterviewScheduled::class, CreateInterviewScheduledNotification::class);
        Event::listen(InterviewUpdated::class, CreateInterviewUpdatedNotification::class);
        Event::listen(InterviewCancelled::class, CreateInterviewCancelledNotification::class);
        Event::listen(InterviewEvaluated::class, CreateInterviewEvaluatedNotification::class);

        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            return rtrim((string) config('app.url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode((string) $notifiable->getEmailForPasswordReset());
        });

        JsonResource::withoutWrapping();
    }
}

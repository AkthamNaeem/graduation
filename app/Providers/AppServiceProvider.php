<?php

namespace App\Providers;

use App\Models\ApplicationTestAssignment;
use App\Models\JobPosting;
use App\Models\JobApplication;
use App\Models\TestAttempt;
use App\Policies\ApplicationTestAssignmentPolicy;
use App\Policies\JobApplicationPolicy;
use App\Policies\JobPostingPolicy;
use App\Policies\TestAttemptPolicy;
use Illuminate\Http\Resources\Json\JsonResource;
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
        Gate::policy(TestAttempt::class, TestAttemptPolicy::class);

        JsonResource::withoutWrapping();
    }
}

<?php

namespace App\Providers;

use App\Models\JobPosting;
use App\Policies\JobPostingPolicy;
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

        JsonResource::withoutWrapping();
    }
}

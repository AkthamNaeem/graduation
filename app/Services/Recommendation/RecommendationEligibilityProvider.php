<?php

namespace App\Services\Recommendation;

use App\Contracts\Recommendation\RecommendationEligibilityProviderContract;
use App\Data\Recommendation\RecommendationEligibility;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\JobPosting;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class RecommendationEligibilityProvider implements RecommendationEligibilityProviderContract
{
    public function eligibleJobs(User $user, Carbon $now): RecommendationEligibility
    {
        if ($user->status !== UserStatus::ACTIVE || $user->role !== UserRole::JOB_SEEKER) {
            throw ValidationException::withMessages([
                'user' => [__('errors.recommendation_active')],
            ]);
        }

        $profile = $user->jobSeekerProfile()
            ->with(['skills', 'experiences', 'education'])
            ->first();
        if (! $profile instanceof JobSeekerProfile) {
            throw ValidationException::withMessages([
                'job_seeker_profile' => [
                    __('errors.recommendation_profile'),
                ],
            ]);
        }

        $jobs = JobPosting::query()
            ->with(['company', 'skills'])
            ->where('status', 'open')
            ->whereHas(
                'company',
                fn ($query) => $query->where('approval_status', 'approved'),
            )
            ->where(function ($query) use ($now): void {
                $query->whereNull('application_deadline')
                    ->orWhere('application_deadline', '>=', $now);
            })
            ->whereDoesntHave(
                'jobApplications',
                fn ($query) => $query->where('job_seeker_profile_id', $profile->id),
            )
            ->orderBy('id')
            ->get()
            ->all();

        return new RecommendationEligibility($profile, $jobs, $now);
    }
}

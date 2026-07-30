<?php

namespace App\Services\Home;

use App\Enums\ApplicationInformationRequestStatus;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationTestAssignment;
use App\Models\CVFile;
use App\Models\Interview;
use App\Models\JobSeekerProfile;
use App\Models\ProfileChangeSuggestion;
use Illuminate\Database\Eloquent\Builder;

class HomeActionResolver
{
    /**
     * @param  array<string, mixed>  $profileCompleteness
     * @return array<string, mixed>|null
     */
    public function resolve(
        JobSeekerProfile $profile,
        array $profileCompleteness,
    ): ?array {
        return $this->pendingTest($profile)
            ?? $this->upcomingInterview($profile)
            ?? $this->informationRequest($profile)
            ?? $this->cvReview($profile)
            ?? $this->profileSyncSuggestion($profile)
            ?? $this->incompleteProfile($profileCompleteness);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pendingTest(JobSeekerProfile $profile): ?array
    {
        $assignment = ApplicationTestAssignment::query()
            ->whereHas(
                'jobApplication',
                fn (Builder $query) => $query->where(
                    'job_seeker_profile_id',
                    $profile->id,
                ),
            )
            ->whereDoesntHave('nextAssignment')
            ->whereDoesntHave(
                'testAttempts',
                fn (Builder $query) => $query->whereNotNull('submitted_at'),
            )
            ->whereDoesntHave(
                'testAttempts',
                fn (Builder $query) => $query
                    ->whereNotNull('effective_deadline_at')
                    ->where('effective_deadline_at', '<', now()),
            )
            ->where(function (Builder $query): void {
                $query->whereNull('deadline_at')
                    ->orWhere('deadline_at', '>=', now());
            })
            ->with([
                'test:id,title',
                'testAttempts:id,application_test_assignment_id,started_at,effective_deadline_at',
            ])
            ->orderByRaw('CASE WHEN deadline_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('deadline_at')
            ->orderBy('id')
            ->first();

        if ($assignment === null) {
            return null;
        }

        $attempt = $assignment->testAttempts
            ->sortByDesc('started_at')
            ->first();

        return [
            'type' => $attempt === null ? 'pending_test' : 'started_test',
            'priority' => 100,
            'title' => $attempt === null
                ? __('home.actions.test_required')
                : __('home.actions.test_incomplete'),
            'subtitle' => $assignment->test?->title,
            'deadline' => ($attempt?->effective_deadline_at ?? $assignment->deadline_at)
                ?->toISOString(),
            'target' => [
                'type' => 'test_assignment',
                'id' => $assignment->id,
            ],
            'action_label' => $attempt === null
                ? __('home.actions.start_test')
                : __('home.actions.continue_test'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function upcomingInterview(JobSeekerProfile $profile): ?array
    {
        $interview = Interview::query()
            ->whereHas(
                'jobApplication',
                fn (Builder $query) => $query->where(
                    'job_seeker_profile_id',
                    $profile->id,
                ),
            )
            ->whereIn('status', ['scheduled', 'confirmed', 'rescheduled'])
            ->where('scheduled_at', '>=', now())
            ->with('jobApplication.jobPosting.company:id,name')
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->first();

        if ($interview === null) {
            return null;
        }

        $companyName = $interview->jobApplication?->jobPosting?->company?->name;

        return [
            'type' => $interview->confirmed_at === null
                ? 'interview_confirmation'
                : 'upcoming_interview',
            'priority' => 90,
            'title' => $interview->confirmed_at === null
                ? __('home.actions.interview_confirmation')
                : __('home.actions.upcoming_interview'),
            'subtitle' => $companyName === null
                ? __('home.actions.interview_subtitle', [
                    'type' => $interview->interview_type,
                    'company' => '',
                ])
                : __('home.actions.interview_subtitle_with_company', [
                    'type' => $interview->interview_type,
                    'company' => $companyName,
                ]),
            'date_time' => $interview->scheduled_at?->toISOString(),
            'target' => [
                'type' => 'interview',
                'id' => $interview->id,
            ],
            'action_label' => $interview->confirmed_at === null
                ? __('home.actions.confirm_attendance')
                : __('home.actions.view_details'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function informationRequest(JobSeekerProfile $profile): ?array
    {
        $informationRequest = ApplicationInformationRequest::query()
            ->whereHas(
                'jobApplication',
                fn (Builder $query) => $query->where(
                    'job_seeker_profile_id',
                    $profile->id,
                ),
            )
            ->where('status', ApplicationInformationRequestStatus::PENDING)
            ->whereDoesntHave('response')
            ->where(function (Builder $query): void {
                $query->whereNull('due_at')
                    ->orWhere('due_at', '>=', now());
            })
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('id')
            ->first();

        if ($informationRequest === null) {
            return null;
        }

        return [
            'type' => 'information_request',
            'priority' => 80,
            'title' => __('home.actions.information_required'),
            'subtitle' => $informationRequest->message,
            'deadline' => $informationRequest->due_at?->toISOString(),
            'target' => [
                'type' => 'information_request',
                'id' => $informationRequest->id,
            ],
            'action_label' => __('home.actions.submit_information'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cvReview(JobSeekerProfile $profile): ?array
    {
        $cv = CVFile::query()
            ->select(['id', 'user_id', 'original_name', 'review_mode', 'review_status', 'confirmed_at'])
            ->where('user_id', $profile->user_id)
            ->where('status', 'parsed')
            ->whereNull('confirmed_at')
            ->where(function (Builder $query): void {
                $query->whereNull('review_status')
                    ->orWhereNotIn('review_status', [CVFile::REVIEW_STATUS_APPLIED]);
            })
            ->latest('id')
            ->first();

        if ($cv === null) {
            return null;
        }

        return [
            'type' => 'cv_review',
            'priority' => 70,
            'title' => __('home.actions.review_cv'),
            'subtitle' => $cv->original_name,
            'target' => [
                'type' => 'cv_review',
                'id' => $cv->id,
            ],
            'action_label' => __('home.actions.review_cv_action'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function profileSyncSuggestion(JobSeekerProfile $profile): ?array
    {
        $suggestion = ProfileChangeSuggestion::query()
            ->select(['id', 'job_seeker_profile_id'])
            ->where('job_seeker_profile_id', $profile->id)
            ->where('status', ProfileChangeSuggestion::STATUS_PENDING)
            ->oldest('id')
            ->first();

        if ($suggestion === null) {
            return null;
        }

        return [
            'type' => 'profile_sync',
            'priority' => 60,
            'title' => __('home.actions.profile_suggestions'),
            'subtitle' => __('home.actions.profile_suggestions_subtitle'),
            'target' => [
                'type' => 'profile_suggestions',
                'value' => 'pending',
            ],
            'action_label' => __('home.actions.review_suggestions'),
        ];
    }

    /**
     * @param  array<string, mixed>  $profileCompleteness
     * @return array<string, mixed>|null
     */
    private function incompleteProfile(array $profileCompleteness): ?array
    {
        if ($profileCompleteness['is_complete']) {
            return null;
        }

        return [
            'type' => 'profile_incomplete',
            'priority' => 50,
            'title' => __('home.actions.complete_profile'),
            'subtitle' => __('home.actions.profile_progress', [
                'percentage' => $profileCompleteness['percentage'],
            ]),
            'target' => $profileCompleteness['next_item']['target'] ?? [
                'type' => 'profile_section',
                'value' => 'basic_information',
            ],
            'action_label' => __('home.actions.complete_profile_action'),
        ];
    }
}

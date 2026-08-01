<?php

namespace App\Services;

use App\Enums\ApplicationInformationRequestStatus;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationStatusHistory;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\User;
use App\Support\LocalizedValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ApplicationPageService
{
    /** @var array<int, string> */
    private const TERMINAL_STATUSES = ['accepted', 'rejected', 'withdrawn'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{applications: LengthAwarePaginator<int, JobApplication>, counts: array<string, int>}
     */
    public function getMyApplications(User $user, array $filters): array
    {
        $profileId = $user->jobSeekerProfile?->id;
        $query = $this->baseQuery($profileId);

        $this->applySearch($query, trim((string) ($filters['search'] ?? '')));
        $this->applyStatusFilter($query, $filters['status'] ?? []);
        $this->applyGroup($query, (string) ($filters['group'] ?? 'all'));
        $this->applySort(
            $query,
            (string) ($filters['sort_by'] ?? 'priority'),
            (string) ($filters['sort_direction'] ?? 'desc'),
        );

        $applications = $query->paginate((int) ($filters['per_page'] ?? 15));
        $this->attachPresentations($applications->getCollection(), $user);

        return [
            'applications' => $applications,
            'counts' => $this->counts($profileId),
        ];
    }

    public function attachPresentation(JobApplication $application, User $viewer): void
    {
        $application->setAttribute('application_page', $this->presentation($application, $viewer));
    }

    /**
     * @param  Collection<int, JobApplication>  $applications
     */
    private function attachPresentations(Collection $applications, User $viewer): void
    {
        $applications->each(fn (JobApplication $application) => $this->attachPresentation($application, $viewer));
    }

    /** @return Builder<JobApplication> */
    private function baseQuery(?int $profileId): Builder
    {
        return JobApplication::query()
            ->where('job_seeker_profile_id', $profileId)
            ->with([
                'jobPosting.company',
                'jobPosting.city',
                'selectedCvFile',
                'applicationStatus',
                'latestStatusHistory',
                'latestInformationRequest.response',
                'latestTestAssignment.testAttempt',
                'upcomingInterview',
                'latestInterview',
            ])
            ->withExists([
                'latestTestAssignment as has_test_event' => fn (Builder $query) => $query
                    ->whereDoesntHave('testAttempt', fn (Builder $attempt) => $attempt->whereNotNull('submitted_at')),
                'latestInformationRequest as has_information_action' => fn (Builder $query) => $query
                    ->where('status', ApplicationInformationRequestStatus::PENDING->value)
                    ->whereDoesntHave('response')
                    ->where(fn (Builder $due) => $due->whereNull('due_at')->orWhere('due_at', '>=', now())),
                'upcomingInterview as has_upcoming_interview',
                'upcomingInterview as has_interview_confirmation' => fn (Builder $query) => $query->whereNull('confirmed_at'),
            ])
            ->addSelect([
                'last_status_changed_at' => ApplicationStatusHistory::query()
                    ->select('created_at')
                    ->whereColumn('job_application_id', 'job_applications.id')
                    ->latest('id')
                    ->limit(1),
                'test_deadline_at' => ApplicationTestAssignment::query()
                    ->select('deadline_at')
                    ->whereColumn('job_application_id', 'job_applications.id')
                    ->latest('id')
                    ->limit(1),
                'information_deadline_at' => ApplicationInformationRequest::query()
                    ->select('due_at')
                    ->whereColumn('job_application_id', 'job_applications.id')
                    ->latest('id')
                    ->limit(1),
                'interview_starts_at' => Interview::query()
                    ->select('scheduled_at')
                    ->whereColumn('job_application_id', 'job_applications.id')
                    ->where('status', 'scheduled')
                    ->where('scheduled_at', '>=', now())
                    ->oldest('scheduled_at')
                    ->limit(1),
            ]);
    }

    /** @param Builder<JobApplication> $query */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $like = "%{$escaped}%";

        $query->whereHas('jobPosting', function (Builder $job) use ($like): void {
            $job->where(function (Builder $match) use ($like): void {
                $match->where('title', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', $like))
                    ->orWhereHas('city', fn (Builder $city) => $city
                        ->where('name_ar', 'like', $like)
                        ->orWhere('name_en', 'like', $like));
            });
        });
    }

    /**
     * @param  Builder<JobApplication>  $query
     * @param  array<int, string>  $statuses
     */
    private function applyStatusFilter(Builder $query, array $statuses): void
    {
        if ($statuses !== []) {
            $query->whereHas('applicationStatus', fn (Builder $status) => $status->whereIn('slug', $statuses));
        }
    }

    /** @param Builder<JobApplication> $query */
    private function applyGroup(Builder $query, string $group): void
    {
        match ($group) {
            'active' => $query->whereHas('applicationStatus', fn (Builder $status) => $status->whereNotIn('slug', self::TERMINAL_STATUSES)),
            'completed' => $query->whereHas('applicationStatus', fn (Builder $status) => $status->whereIn('slug', self::TERMINAL_STATUSES)),
            'requires_action' => $this->whereRequiresAction($query),
            default => null,
        };
    }

    /** @param Builder<JobApplication> $query */
    private function whereRequiresAction(Builder $query): void
    {
        $query->where(function (Builder $action): void {
            $action->where(function (Builder $test): void {
                $test->whereHas('applicationStatus', fn (Builder $status) => $status->where('slug', 'test_pending'))
                    ->whereHas('latestTestAssignment', fn (Builder $assignment) => $assignment
                        ->whereDoesntHave('testAttempt', fn (Builder $attempt) => $attempt->whereNotNull('submitted_at')));
            })->orWhere(function (Builder $information): void {
                $information->whereHas('applicationStatus', fn (Builder $status) => $status->where('slug', 'need_more_information'))
                    ->whereHas('latestInformationRequest', fn (Builder $request) => $request
                        ->where('status', ApplicationInformationRequestStatus::PENDING->value)
                        ->whereDoesntHave('response')
                        ->where(fn (Builder $due) => $due->whereNull('due_at')->orWhere('due_at', '>=', now())));
            })->orWhere(function (Builder $interview): void {
                $interview->whereHas('applicationStatus', fn (Builder $status) => $status->where('slug', 'interview_scheduled'))
                    ->whereHas('upcomingInterview', fn (Builder $scheduled) => $scheduled->whereNull('confirmed_at'));
            });
        });
    }

    /** @param Builder<JobApplication> $query */
    private function applySort(Builder $query, string $sortBy, string $direction): void
    {
        if ($sortBy === 'priority') {
            $now = now()->format('Y-m-d H:i:s');
            $query->orderByRaw("CASE
                WHEN ((has_test_event = 1 AND application_status_id = (SELECT id FROM application_statuses WHERE slug = 'test_pending')) OR has_information_action = 1 OR has_interview_confirmation = 1)
                    AND COALESCE(test_deadline_at, information_deadline_at, interview_starts_at) < ? THEN 7
                WHEN ((has_test_event = 1 AND application_status_id = (SELECT id FROM application_statuses WHERE slug = 'test_pending')) OR has_information_action = 1 OR has_interview_confirmation = 1) THEN 6
                WHEN has_upcoming_interview = 1 THEN 5
                WHEN has_test_event = 1 THEN 4
                WHEN last_status_changed_at IS NOT NULL THEN 3
                WHEN application_status_id NOT IN (SELECT id FROM application_statuses WHERE slug IN ('accepted', 'rejected', 'withdrawn')) THEN 2
                ELSE 1 END {$direction}", [$now])
                ->orderByDesc('last_status_changed_at')
                ->orderByDesc('job_applications.id');

            return;
        }

        if ($sortBy === 'deadline') {
            $query->orderByRaw('CASE WHEN COALESCE(test_deadline_at, information_deadline_at, interview_starts_at) IS NULL THEN 1 ELSE 0 END')
                ->orderByRaw("COALESCE(test_deadline_at, information_deadline_at, interview_starts_at) {$direction}")
                ->orderByDesc('last_status_changed_at');

            return;
        }

        $column = $sortBy === 'last_status_changed_at'
            ? 'last_status_changed_at'
            : "job_applications.{$sortBy}";
        $query->orderBy($column, $direction)->orderByDesc('job_applications.id');
    }

    /** @return array<string, int> */
    private function counts(?int $profileId): array
    {
        $statusCounts = JobApplication::query()
            ->where('job_seeker_profile_id', $profileId)
            ->join('application_statuses', 'application_statuses.id', '=', 'job_applications.application_status_id')
            ->selectRaw('application_statuses.slug, COUNT(*) as aggregate')
            ->groupBy('application_statuses.slug')
            ->pluck('aggregate', 'slug');

        $completed = collect(self::TERMINAL_STATUSES)->sum(fn (string $slug): int => (int) ($statusCounts[$slug] ?? 0));
        $all = (int) $statusCounts->sum();
        $requiresAction = JobApplication::query()
            ->where('job_seeker_profile_id', $profileId)
            ->tap(fn (Builder $query) => $this->whereRequiresAction($query))
            ->count();

        return [
            'all' => $all,
            'active' => $all - $completed,
            'requires_action' => $requiresAction,
            'completed' => $completed,
        ];
    }

    /** @return array<string, mixed> */
    private function presentation(JobApplication $application, User $viewer): array
    {
        $status = $application->applicationStatus?->slug;
        $terminal = in_array($status, self::TERMINAL_STATUSES, true);
        $test = $application->latestTestAssignment;
        $attempt = $test?->testAttempt;
        $information = $application->latestInformationRequest;
        $interview = $application->upcomingInterview ?? $application->latestInterview;
        $testAction = $status === 'test_pending' && $test !== null && $attempt?->submitted_at === null;
        $informationAction = $status === 'need_more_information'
            && $information?->canBeRespondedTo() === true;
        $interviewAction = $status === 'interview_scheduled'
            && $application->upcomingInterview !== null
            && $application->upcomingInterview->confirmed_at === null;

        $nextAction = $this->nextAction($testAction, $informationAction, $interviewAction, $test, $information, $application->upcomingInterview);
        $allowed = ['view'];
        if (! $terminal && $viewer->jobSeekerProfile?->id === $application->job_seeker_profile_id) {
            $allowed[] = 'withdraw';
        }
        if ($testAction) {
            $allowed[] = 'complete_test';
        }
        if ($informationAction) {
            $allowed[] = 'submit_information';
        }
        if ($application->upcomingInterview !== null) {
            $allowed[] = 'view_interview';
        }
        if ($interviewAction) {
            $allowed[] = 'confirm_interview';
        }

        return [
            'requires_action' => $testAction || $informationAction || $interviewAction,
            'next_action' => $nextAction,
            'allowed_actions' => array_values(array_unique($allowed)),
            'last_status_changed_at' => $application->latestStatusHistory?->created_at?->toISOString()
                ?? $application->created_at?->toISOString(),
            'upcoming_event' => $this->upcomingEvent($test, $application->upcomingInterview),
            'current_test' => $test === null ? null : [
                'id' => $test->id,
                'deadline_at' => $test->deadline_at?->toISOString(),
                'is_overdue' => $testAction && $test->deadline_at !== null && now()->isAfter($test->deadline_at),
                'attempt' => $attempt === null ? null : [
                    'id' => $attempt->id,
                    'started_at' => $attempt->started_at?->toISOString(),
                    'submitted_at' => $attempt->submitted_at?->toISOString(),
                ],
            ],
            'relevant_interview' => $interview === null ? null : [
                'id' => $interview->id,
                'status' => LocalizedValue::make($interview->status, 'interview_statuses'),
                'scheduled_at' => $interview->scheduled_at?->toISOString(),
                'scheduled_end_at' => $interview->scheduled_end_at?->toISOString(),
                'requires_confirmation' => $interview->status === 'scheduled' && $interview->confirmed_at === null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function nextAction(
        bool $testAction,
        bool $informationAction,
        bool $interviewAction,
        ?ApplicationTestAssignment $test,
        ?ApplicationInformationRequest $information,
        ?Interview $interview,
    ): ?array {
        $candidates = [];
        if ($testAction && $test !== null) {
            $candidates[] = $this->action('complete_test', $test->id, $test->deadline_at, "/api/v1/tests/{$test->id}/start");
        }
        if ($informationAction && $information !== null) {
            $candidates[] = $this->action('submit_information', $information->id, $information->due_at, "/api/v1/information-requests/{$information->id}/respond");
        }
        if ($interviewAction && $interview !== null) {
            $candidates[] = $this->action('confirm_interview', $interview->id, $interview->scheduled_at, "/api/v1/interviews/{$interview->id}/confirm");
        }

        if ($candidates !== []) {
            usort($candidates, fn (array $left, array $right): int => ($left['_timestamp'] ?? PHP_INT_MAX) <=> ($right['_timestamp'] ?? PHP_INT_MAX));
            unset($candidates[0]['_timestamp']);

            return $candidates[0];
        }

        if ($interview !== null) {
            return $this->action('view_interview', $interview->id, $interview->scheduled_at, "/api/v1/interviews/{$interview->id}", false);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function action(string $type, int $resourceId, ?Carbon $deadline, string $url, bool $overdue = true): array
    {
        return [
            'type' => LocalizedValue::make($type, 'application_action_types'),
            'resource_id' => $resourceId,
            'deadline_at' => $deadline?->toISOString(),
            'is_overdue' => $overdue && $deadline !== null && now()->isAfter($deadline),
            'url' => $url,
            '_timestamp' => $deadline?->getTimestamp(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function upcomingEvent(?ApplicationTestAssignment $test, ?Interview $interview): ?array
    {
        $events = [];
        if ($test?->deadline_at !== null && $test->testAttempt?->submitted_at === null) {
            $events[] = [
                'type' => LocalizedValue::make('test', 'application_event_types'),
                'resource_id' => $test->id,
                'starts_at' => null,
                'deadline_at' => $test->deadline_at->toISOString(),
                '_timestamp' => $test->deadline_at->getTimestamp(),
            ];
        }
        if ($interview !== null) {
            $events[] = [
                'type' => LocalizedValue::make('interview', 'application_event_types'),
                'resource_id' => $interview->id,
                'starts_at' => $interview->scheduled_at?->toISOString(),
                'deadline_at' => null,
                '_timestamp' => $interview->scheduled_at?->getTimestamp(),
            ];
        }

        if ($events === []) {
            return null;
        }

        usort($events, fn (array $left, array $right): int => ($left['_timestamp'] ?? PHP_INT_MAX) <=> ($right['_timestamp'] ?? PHP_INT_MAX));
        unset($events[0]['_timestamp']);

        return $events[0];
    }
}

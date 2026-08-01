<?php

namespace App\Services\Activity;

use App\Enums\ActivityType;
use App\Enums\ApplicationInformationRequestStatus;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\Notification;
use App\Models\User;
use App\Support\LocalizedValue;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

class ActivityService
{
    private const ACTION_LIMIT = 20;

    public function __construct(
        private readonly ActivityActionResolver $resolver,
        private readonly ActivityScheduleService $scheduleService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary:array<string,int>,upcoming_schedule:array<int,array<string,mixed>>,requires_action:array<int,array<string,mixed>>,feed:LengthAwarePaginator}
     */
    public function index(User $user, array $filters): array
    {
        $profileId = (int) $user->jobSeekerProfile()->value('id');
        abort_if($profileId === 0, 403, __('activity.role_not_allowed'));

        $filters += [
            'group' => 'all',
            'sort_by' => 'priority',
            'sort_direction' => 'desc',
            'per_page' => 15,
            'schedule_limit' => 5,
            'timezone' => config('app.timezone'),
        ];
        $dates = ActivityDateRange::make((string) $filters['timezone']);
        $actions = $this->actions($profileId, $filters, $dates);
        $feedBase = $this->feedQuery($user, $filters, false, $dates);
        $summary = $this->summary($user, $feedBase, $this->actionCounts($profileId, $filters, $dates), $dates);

        if ($filters['group'] === 'requires_action') {
            $feed = new LengthAwarePaginator([], 0, (int) $filters['per_page'], (int) ($filters['page'] ?? 1), [
                'path' => request()->url(),
                'pageName' => 'page',
            ]);
        } else {
            $query = $this->feedQuery($user, $filters, true, $dates);
            $this->sortFeed($query, (string) $filters['sort_by'], (string) $filters['sort_direction']);
            $feed = $query->paginate((int) $filters['per_page']);
            $feed->setCollection($this->notificationItems($feed->getCollection(), $profileId, $dates));
        }

        return [
            'summary' => $summary,
            'upcoming_schedule' => $this->scheduleService->get(
                $profileId,
                $filters,
                $dates,
                (int) $filters['schedule_limit'],
            ),
            'requires_action' => array_values(array_filter(
                $actions,
                fn (array $item): bool => $filters['group'] !== 'requires_action' || $item['requires_action'],
            )),
            'feed' => $feed,
        ];
    }

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    private function actions(int $profileId, array $filters, ActivityDateRange $dates): array
    {
        $types = $filters['type'] ?? [];
        $items = collect();

        if ($types === [] || in_array(ActivityType::TEST->value, $types, true)) {
            $query = $this->testActionQuery($profileId, $filters, $dates)
                ->with([...$this->applicationRelations(), 'testAttempts']);
            $query->limit(self::ACTION_LIMIT)->get()->each(
                fn (ApplicationTestAssignment $assignment) => $items->push($this->resolver->test($assignment, $dates)),
            );
        }

        if ($types === [] || in_array(ActivityType::INTERVIEW->value, $types, true)) {
            $query = $this->interviewActionQuery($profileId, $filters, $dates)
                ->with($this->applicationRelations());
            $query->limit(self::ACTION_LIMIT)->get()->each(
                fn (Interview $interview) => $items->push($this->resolver->interview($interview, $dates)),
            );
        }

        if ($types === [] || in_array(ActivityType::INFORMATION_REQUEST->value, $types, true)) {
            $query = $this->informationActionQuery($profileId, $filters, $dates)
                ->with([...$this->applicationRelations(), 'response']);
            $query->limit(self::ACTION_LIMIT)->get()->each(
                fn (ApplicationInformationRequest $request) => $items->push($this->resolver->informationRequest($request, $dates)),
            );
        }

        $sortBy = (string) $filters['sort_by'];
        $direction = (string) $filters['sort_direction'];

        return $items
            ->unique('activity_key')
            ->sort(function (array $left, array $right) use ($sortBy, $direction): int {
                if ($sortBy === 'due_at' && (($left['due_at'] === null) !== ($right['due_at'] === null))) {
                    return $left['due_at'] === null ? 1 : -1;
                }

                $comparison = $sortBy === 'occurred_at'
                    ? strcmp((string) $left['occurred_at'], (string) $right['occurred_at'])
                    : ($sortBy === 'due_at'
                        ? strcmp((string) $left['due_at'], (string) $right['due_at'])
                        : $left['priority'] <=> $right['priority']);
                if ($comparison !== 0) {
                    return $direction === 'asc' ? $comparison : -$comparison;
                }

                return strcmp((string) ($left['due_at'] ?? '9999-12-31'), (string) ($right['due_at'] ?? '9999-12-31'))
                    ?: strcmp((string) $right['occurred_at'], (string) $left['occurred_at'])
                    ?: ((int) $right['source']['id'] <=> (int) $left['source']['id']);
            })
            ->take(self::ACTION_LIMIT)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function feedQuery(User $user, array $filters, bool $applyGroup, ActivityDateRange $dates): Builder
    {
        $query = Notification::query()->where('user_id', $user->id);
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function (Builder $match) use ($like): void {
                $match->where('title', 'like', $like)
                    ->orWhere('message', 'like', $like)
                    ->orWhere('data->job_title', 'like', $like)
                    ->orWhere('data->company_name', 'like', $like);
            });
        }

        $this->applyFeedTypes($query, $filters['type'] ?? []);
        if (isset($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'], $dates->timezone)->startOfDay()->utc());
        }
        if (isset($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'], $dates->timezone)->endOfDay()->utc());
        }
        if ($applyGroup && $filters['group'] === 'today') {
            $query->whereBetween('created_at', [$dates->todayStart, $dates->todayEnd]);
        } elseif ($applyGroup && $filters['group'] === 'this_week') {
            $query->whereBetween('created_at', [$dates->weekStart, $dates->weekEnd]);
        }

        return $query;
    }

    /** @param Builder<Notification> $query @param array<int, string> $types */
    private function applyFeedTypes(Builder $query, array $types): void
    {
        if ($types === []) {
            return;
        }

        $query->where(function (Builder $matches) use ($types): void {
            $matches->whereIn('data->activity_type', $types);
            foreach ($types as $type) {
                match ($type) {
                    'test' => $matches->orWhere('type', 'like', 'test.%'),
                    'interview' => $matches->orWhere('type', 'like', 'interview.%'),
                    'information_request' => $matches->orWhere('type', 'like', '%information%'),
                    'application_reminder' => $matches->orWhere('type', 'like', '%reminder%'),
                    'final_decision' => $matches->orWhere('type', 'like', 'final.%'),
                    'application_status' => $matches->orWhere(fn (Builder $status) => $status
                        ->where('type', 'like', 'application.%')
                        ->where('type', 'not like', '%information%'))
                        ->orWhere(fn (Builder $legacy) => $legacy
                            ->where('type', 'not like', 'application.%')
                            ->where('type', 'not like', 'test.%')
                            ->where('type', 'not like', 'interview.%')
                            ->where('type', 'not like', 'final.%')
                            ->where('type', 'not like', '%information%')
                            ->where('type', 'not like', '%reminder%')),
                    default => null,
                };
            }
        });
    }

    /** @param Builder<Notification> $query */
    private function sortFeed(Builder $query, string $sortBy, string $direction): void
    {
        if ($sortBy === 'priority') {
            $query->orderByRaw("CASE
                WHEN type LIKE 'final.%' THEN 5
                WHEN type LIKE 'application.%' THEN 4
                WHEN type LIKE 'interview.%' THEN 3
                WHEN type LIKE 'test.%' THEN 2
                ELSE 1 END {$direction}")
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            return;
        }

        if ($sortBy === 'due_at') {
            $driver = $query->getConnection()->getDriverName();
            $expression = match ($driver) {
                'pgsql' => "COALESCE(data->>'due_at', data->>'deadline_at', data->>'scheduled_at')",
                'sqlite' => "COALESCE(json_extract(data, '$.due_at'), json_extract(data, '$.deadline_at'), json_extract(data, '$.scheduled_at'))",
                default => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.due_at')), JSON_UNQUOTE(JSON_EXTRACT(data, '$.deadline_at')), JSON_UNQUOTE(JSON_EXTRACT(data, '$.scheduled_at')))",
            };
            $query->orderByRaw("CASE WHEN {$expression} IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("{$expression} {$direction}")
                ->orderByDesc('created_at')
                ->orderByDesc('id');

            return;
        }

        $query->orderBy('created_at', $direction)->orderBy('id', $direction);
    }

    /** @param Collection<int, Notification> $notifications @return Collection<int, array<string, mixed>> */
    private function notificationItems(Collection $notifications, int $profileId, ActivityDateRange $dates): Collection
    {
        $applicationIds = $notifications
            ->map(fn (Notification $notification) => $notification->data['application_id'] ?? $notification->data['job_application_id'] ?? null)
            ->filter(fn (mixed $id) => is_numeric($id))
            ->map(fn (mixed $id) => (int) $id)
            ->unique();
        $applications = JobApplication::query()
            ->where('job_seeker_profile_id', $profileId)
            ->whereIn('id', $applicationIds)
            ->with(['applicationStatus', 'jobPosting.company'])
            ->get()
            ->keyBy('id');

        return $notifications->map(function (Notification $notification) use ($applications, $dates): array {
            $data = is_array($notification->data) ? $notification->data : [];
            $applicationId = $data['application_id'] ?? $data['job_application_id'] ?? null;
            $application = is_numeric($applicationId) ? $applications->get((int) $applicationId) : null;
            if (is_numeric($applicationId) && $application === null) {
                foreach ([
                    'application_id', 'job_application_id', 'job_posting_id', 'job_id', 'job_title',
                    'company_id', 'company_name', 'resource_type', 'resource_id', 'test_attempt_id',
                    'test_assignment_id', 'interview_id', 'information_request_id', 'action_type',
                ] as $unsafeKey) {
                    unset($data[$unsafeKey]);
                }
            }
            $job = $application?->jobPosting;
            $company = $job?->company;
            $status = $data['status'] ?? $application?->applicationStatus?->slug;
            $statusValue = is_string($status) && Lang::has('options.application_statuses.'.$status)
                ? ['key' => $status, 'label' => LocalizedValue::make($status, 'application_statuses')['value']]
                : null;

            return $this->resolver->notification([
                ...$data,
                'notification_id' => $notification->id,
                'notification_type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'created_at' => $notification->created_at?->toISOString() ?? now()->toISOString(),
                'is_read' => $notification->read_at !== null,
                'read_at' => $notification->read_at?->toISOString(),
                'application' => $application === null ? null : ['id' => $application->id, 'status' => $statusValue],
                'job' => $job === null && ! isset($data['job_title']) ? null : [
                    'id' => $job?->id ?? $data['job_posting_id'] ?? $data['job_id'] ?? null,
                    'title' => $job?->title ?? $data['job_title'] ?? null,
                ],
                'company' => $company === null && ! isset($data['company_name']) ? null : [
                    'id' => $company?->id ?? $data['company_id'] ?? null,
                    'name' => $company?->name ?? $data['company_name'] ?? null,
                    'logo_url' => $company?->logo_path === null ? null : Storage::disk('public')->url($company->logo_path),
                ],
            ], $dates);
        });
    }

    /** @param Builder<Notification> $feedBase @param array<string,int> $actionCounts @return array<string,int> */
    private function summary(User $user, Builder $feedBase, array $actionCounts, ActivityDateRange $dates): array
    {
        $feedTotal = (clone $feedBase)->count();
        $todayFeed = (clone $feedBase)->whereBetween('created_at', [$dates->todayStart, $dates->todayEnd])->count();
        $weekFeed = (clone $feedBase)->whereBetween('created_at', [$dates->weekStart, $dates->weekEnd])->count();

        $feedTypeCount = fn (string $type): int => tap(clone $feedBase, fn (Builder $query) => $this->applyFeedTypes($query, [$type]))->count();

        return [
            'all' => $feedTotal + $actionCounts['all'],
            'requires_action' => $actionCounts['requires_action'],
            'today' => $todayFeed + $actionCounts['today'],
            'this_week' => $weekFeed + $actionCounts['this_week'],
            'tests' => $feedTypeCount('test') + $actionCounts['tests'],
            'interviews' => $feedTypeCount('interview') + $actionCounts['interviews'],
            'information_requests' => $feedTypeCount('information_request') + $actionCounts['information_requests'],
            'status_updates' => $feedTypeCount('application_status') + $feedTypeCount('final_decision'),
            'unread_notifications' => Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count(),
        ];
    }

    /** @param array<string,mixed> $filters @return array<string,int> */
    private function actionCounts(int $profileId, array $filters, ActivityDateRange $dates): array
    {
        $filters['group'] = 'all';
        $types = $filters['type'] ?? [];
        $test = $types === [] || in_array('test', $types, true)
            ? $this->testActionQuery($profileId, $filters, $dates)
            : null;
        $interview = $types === [] || in_array('interview', $types, true)
            ? $this->interviewActionQuery($profileId, $filters, $dates)
            : null;
        $information = $types === [] || in_array('information_request', $types, true)
            ? $this->informationActionQuery($profileId, $filters, $dates)
            : null;

        $testCount = $test === null ? 0 : (clone $test)->count();
        $interviewCount = $interview === null ? 0 : (clone $interview)->count();
        $informationCount = $information === null ? 0 : (clone $information)->count();
        $respondableInformation = $information === null ? 0 : (clone $information)
            ->where(fn (Builder $due) => $due->whereNull('due_at')->orWhere('due_at', '>=', now()))
            ->count();

        $windowCount = function (DateTimeInterface $start, DateTimeInterface $end) use ($test, $interview, $information): int {
            $count = 0;
            if ($test !== null) {
                $query = clone $test;
                $this->whereActionWindow($query, $start, $end, 'assigned_at', 'deadline_at', 'testAttempts', 'effective_deadline_at');
                $count += $query->count();
            }
            if ($interview !== null) {
                $query = clone $interview;
                $this->whereActionWindow($query, $start, $end, 'created_at', 'scheduled_at');
                $count += $query->count();
            }
            if ($information !== null) {
                $query = clone $information;
                $this->whereActionWindow($query, $start, $end, 'created_at', 'due_at');
                $count += $query->count();
            }

            return $count;
        };

        return [
            'all' => $testCount + $interviewCount + $informationCount,
            'requires_action' => $testCount + $interviewCount + $respondableInformation,
            'today' => $windowCount($dates->todayStart, $dates->todayEnd),
            'this_week' => $windowCount($dates->weekStart, $dates->weekEnd),
            'tests' => $testCount,
            'interviews' => $interviewCount,
            'information_requests' => $informationCount,
        ];
    }

    /** @param array<string,mixed> $filters @return Builder<ApplicationTestAssignment> */
    private function testActionQuery(int $profileId, array $filters, ActivityDateRange $dates): Builder
    {
        $query = ApplicationTestAssignment::query()
            ->whereHas('jobApplication', fn (Builder $application) => $application->where('job_seeker_profile_id', $profileId))
            ->whereDoesntHave('nextAssignment')
            ->whereDoesntHave('testAttempts', fn (Builder $attempt) => $attempt->whereNotNull('submitted_at'));
        $this->applyApplicationSearch($query, $filters);
        $this->applyActionWindow($query, $filters, $dates, 'assigned_at', 'deadline_at', 'testAttempts', 'effective_deadline_at');

        return $query;
    }

    /** @param array<string,mixed> $filters @return Builder<Interview> */
    private function interviewActionQuery(int $profileId, array $filters, ActivityDateRange $dates): Builder
    {
        $query = Interview::query()
            ->whereHas('jobApplication', fn (Builder $application) => $application->where('job_seeker_profile_id', $profileId))
            ->whereIn('status', ['scheduled', 'rescheduled'])
            ->whereNull('confirmed_at')
            ->where('scheduled_at', '>=', now());
        $this->applyApplicationSearch($query, $filters);
        $this->applyActionWindow($query, $filters, $dates, 'created_at', 'scheduled_at');

        return $query;
    }

    /** @param array<string,mixed> $filters @return Builder<ApplicationInformationRequest> */
    private function informationActionQuery(int $profileId, array $filters, ActivityDateRange $dates): Builder
    {
        $query = ApplicationInformationRequest::query()
            ->whereHas('jobApplication', fn (Builder $application) => $application->where('job_seeker_profile_id', $profileId))
            ->where('status', ApplicationInformationRequestStatus::PENDING->value)
            ->whereDoesntHave('response');
        $this->applyApplicationSearch($query, $filters, true);
        $this->applyActionWindow($query, $filters, $dates, 'created_at', 'due_at');

        return $query;
    }

    /** @param Builder<*> $query @param array<string,mixed> $filters */
    private function applyApplicationSearch(Builder $query, array $filters, bool $includeMessage = false): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
        $query->where(function (Builder $outer) use ($like, $includeMessage): void {
            $outer->whereHas('jobApplication.jobPosting', function (Builder $job) use ($like): void {
                $job->where(function (Builder $match) use ($like): void {
                    $match->where('title', 'like', $like)
                        ->orWhere('location', 'like', $like)
                        ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', $like))
                        ->orWhereHas('city', fn (Builder $city) => $city->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
                });
            });
            if ($includeMessage) {
                $outer->orWhere('message', 'like', $like);
            }
        });
    }

    /** @param Builder<*> $query @param array<string,mixed> $filters */
    private function applyActionWindow(Builder $query, array $filters, ActivityDateRange $dates, string $occurredColumn, string $dueColumn, ?string $relation = null, ?string $relationColumn = null): void
    {
        if (($filters['group'] ?? 'all') === 'today') {
            $this->whereActionWindow($query, $dates->todayStart, $dates->todayEnd, $occurredColumn, $dueColumn, $relation, $relationColumn);
        } elseif (($filters['group'] ?? 'all') === 'this_week') {
            $this->whereActionWindow($query, $dates->weekStart, $dates->weekEnd, $occurredColumn, $dueColumn, $relation, $relationColumn);
        }
        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $start = isset($filters['date_from'])
                ? Carbon::parse($filters['date_from'], $dates->timezone)->startOfDay()->utc()
                : Carbon::create(1970, 1, 1, timezone: 'UTC');
            $end = isset($filters['date_to'])
                ? Carbon::parse($filters['date_to'], $dates->timezone)->endOfDay()->utc()
                : Carbon::create(9999, 12, 31, timezone: 'UTC');
            $this->whereActionWindow($query, $start, $end, $occurredColumn, $dueColumn, $relation, $relationColumn);
        }
    }

    /** @param Builder<*> $query */
    private function whereActionWindow(Builder $query, DateTimeInterface $start, DateTimeInterface $end, string $occurredColumn, string $dueColumn, ?string $relation = null, ?string $relationColumn = null): void
    {
        $query->where(function (Builder $window) use ($start, $end, $occurredColumn, $dueColumn, $relation, $relationColumn): void {
            $window->whereBetween($occurredColumn, [$start, $end])->orWhereBetween($dueColumn, [$start, $end]);
            if ($relation !== null && $relationColumn !== null) {
                $window->orWhereHas($relation, fn (Builder $related) => $related->whereBetween($relationColumn, [$start, $end]));
            }
        });
    }

    /** @return array<int,string> */
    private function applicationRelations(): array
    {
        return ['jobApplication.applicationStatus', 'jobApplication.jobPosting.company', 'jobApplication.jobPosting.city'];
    }
}

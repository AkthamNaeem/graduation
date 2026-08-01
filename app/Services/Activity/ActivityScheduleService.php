<?php

namespace App\Services\Activity;

use App\Enums\ActivityType;
use App\Enums\ApplicationInformationRequestStatus;
use App\Models\ApplicationInformationRequest;
use App\Models\ApplicationTestAssignment;
use App\Models\Interview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ActivityScheduleService
{
    public function __construct(private readonly ActivityActionResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function get(int $profileId, array $filters, ActivityDateRange $dates, int $limit): array
    {
        $types = $filters['type'] ?? [];
        $items = collect();

        if ($types === [] || in_array(ActivityType::TEST->value, $types, true)) {
            $query = ApplicationTestAssignment::query()
                ->whereHas('jobApplication', fn (Builder $application) => $application->where('job_seeker_profile_id', $profileId))
                ->whereDoesntHave('nextAssignment')
                ->whereDoesntHave('testAttempts', fn (Builder $attempt) => $attempt->whereNotNull('submitted_at'))
                ->where(function (Builder $assignment): void {
                    $assignment->where('deadline_at', '>=', now())
                        ->orWhereHas('testAttempts', fn (Builder $attempt) => $attempt
                            ->whereNull('submitted_at')
                            ->where('effective_deadline_at', '>=', now()));
                })
                ->with($this->assignmentRelations());
            $this->applyApplicationSearch($query, $filters);
            $this->applyScheduleWindow($query, $filters, $dates, 'deadline_at');
            $query->orderByRaw('CASE WHEN deadline_at IS NULL THEN 1 ELSE 0 END')->orderBy('deadline_at')->limit($limit);
            $query->get()->each(function (ApplicationTestAssignment $assignment) use ($items, $dates): void {
                $action = $this->resolver->test($assignment, $dates);
                $items->push($this->scheduleItem($action));
            });
        }

        if ($types === [] || in_array(ActivityType::INTERVIEW->value, $types, true)) {
            $query = Interview::query()
                ->whereHas('jobApplication', fn (Builder $application) => $application->where('job_seeker_profile_id', $profileId))
                ->whereIn('status', ['scheduled', 'confirmed', 'rescheduled'])
                ->where('scheduled_at', '>=', now())
                ->with($this->applicationRelations());
            $this->applyApplicationSearch($query, $filters);
            $this->applyScheduleWindow($query, $filters, $dates, 'scheduled_at');
            $query->orderBy('scheduled_at')->limit($limit);
            $query->get()->each(function (Interview $interview) use ($items, $dates): void {
                $items->push($this->scheduleItem($this->resolver->interview($interview, $dates)));
            });
        }

        if ($types === [] || in_array(ActivityType::INFORMATION_REQUEST->value, $types, true)) {
            $query = ApplicationInformationRequest::query()
                ->whereHas('jobApplication', fn (Builder $application) => $application->where('job_seeker_profile_id', $profileId))
                ->where('status', ApplicationInformationRequestStatus::PENDING->value)
                ->whereDoesntHave('response')
                ->where('due_at', '>=', now())
                ->with([...$this->applicationRelations(), 'response']);
            $this->applyApplicationSearch($query, $filters);
            $this->applyScheduleWindow($query, $filters, $dates, 'due_at');
            $query->orderBy('due_at')->limit($limit);
            $query->get()->each(function (ApplicationInformationRequest $request) use ($items, $dates): void {
                $items->push($this->scheduleItem($this->resolver->informationRequest($request, $dates)));
            });
        }

        return $items
            ->when(($filters['group'] ?? 'all') === 'requires_action', fn (Collection $collection) => $collection->where('requires_action', true))
            ->unique('activity_key')
            ->sortBy(fn (array $item) => $item['starts_at'] ?? $item['due_at'] ?? '9999-12-31T23:59:59Z')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function scheduleItem(array $item): array
    {
        return [
            'activity_key' => $item['activity_key'],
            'type' => $item['type'],
            'title' => $item['title'],
            'application_id' => $item['application']['id'],
            'job_title' => $item['job']['title'] ?? null,
            'company' => $item['company'],
            'starts_at' => $item['starts_at'],
            'due_at' => $item['due_at'],
            'is_today' => $item['is_today'],
            'is_this_week' => $item['is_this_week'],
            'requires_action' => $item['requires_action'],
            'target' => $item['action']['target'] ?? $item['source'],
        ];
    }

    /** @param Builder<*> $query @param array<string, mixed> $filters */
    private function applyApplicationSearch(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
        $query->whereHas('jobApplication.jobPosting', function (Builder $job) use ($like): void {
            $job->where(function (Builder $match) use ($like): void {
                $match->where('title', 'like', $like)
                    ->orWhere('location', 'like', $like)
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', $like))
                    ->orWhereHas('city', fn (Builder $city) => $city->where('name_ar', 'like', $like)->orWhere('name_en', 'like', $like));
            });
        });
    }

    /** @param Builder<*> $query @param array<string, mixed> $filters */
    private function applyScheduleWindow(Builder $query, array $filters, ActivityDateRange $dates, string $column): void
    {
        $group = $filters['group'] ?? 'all';
        if ($group === 'today') {
            $query->whereBetween($column, [$dates->todayStart, $dates->todayEnd]);
        } elseif ($group === 'this_week') {
            $query->whereBetween($column, [$dates->weekStart, $dates->weekEnd]);
        }

        if (isset($filters['date_from'])) {
            $query->where($column, '>=', Carbon::parse($filters['date_from'], $dates->timezone)->startOfDay()->utc());
        }
        if (isset($filters['date_to'])) {
            $query->where($column, '<=', Carbon::parse($filters['date_to'], $dates->timezone)->endOfDay()->utc());
        }
    }

    /** @return array<int, string> */
    private function assignmentRelations(): array
    {
        return [...$this->applicationRelations(), 'testAttempts'];
    }

    /** @return array<int, string> */
    private function applicationRelations(): array
    {
        return ['jobApplication.applicationStatus', 'jobApplication.jobPosting.company', 'jobApplication.jobPosting.city'];
    }
}

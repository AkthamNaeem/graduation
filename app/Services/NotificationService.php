<?php

namespace App\Services;

use App\Models\JobApplication;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class NotificationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
    ): Notification {
        $data = $this->activityPayload($type, $data);
        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data === [] ? null : $data,
        ]);

        if (($data['activity_key'] ?? null) === null) {
            $data['activity_key'] = 'notification:'.$notification->id;
            $notification->forceFill(['data' => $data])->save();
        }

        return $notification;
    }

    /**
     * @param  Collection<int, User>|array<int, User>  $users
     * @param  array<string, mixed>  $data
     * @return Collection<int, Notification>
     */
    public function createForUsers(
        Collection|array $users,
        string $type,
        string $title,
        string $message,
        array $data = [],
    ): Collection {
        return collect($users)
            ->filter(fn (mixed $user): bool => $user instanceof User)
            ->map(fn (User $user): Notification => $this->createForUser($user, $type, $title, $message, $data))
            ->values();
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function createNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?array $data = null,
    ): Notification {
        $user = User::query()->findOrFail($userId);

        return $this->createForUser($user, $type, $title, $message, $data ?? []);
    }

    public function markAsRead(Notification $notification, User $user): Notification
    {
        abort_unless($notification->user_id === $user->id, 404);

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->refresh();
    }

    public function markAllAsRead(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function delete(Notification $notification, User $user): void
    {
        abort_unless($notification->user_id === $user->id, 404);

        $notification->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Notification>
     */
    public function getUserNotifications(User $user, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->when(array_key_exists('is_read', $filters), function (Builder $query) use ($filters): void {
                $filters['is_read']
                    ? $query->whereNotNull('read_at')
                    : $query->whereNull('read_at');
            })
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    public function getUnreadCount(int $userId): int
    {
        $user = User::query()->findOrFail($userId);

        return $this->unreadCount($user);
    }

    /**
     * Adds the versioned, privacy-safe Activity feed contract while retaining all
     * legacy keys consumed by existing clients.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function activityPayload(string $notificationType, array $data): array
    {
        $applicationId = $data['application_id'] ?? $data['job_application_id'] ?? null;
        if (is_numeric($applicationId)) {
            $application = JobApplication::query()
                ->with('jobPosting.company')
                ->find((int) $applicationId);
            $job = $application?->jobPosting;
            $company = $job?->company;
            $data['job_posting_id'] ??= $job?->id;
            $data['job_title'] ??= $job?->title;
            $data['company_id'] ??= $company?->id;
            $data['company_name'] ??= $company?->name;
        }

        $activityType = match (true) {
            str_starts_with($notificationType, 'test.') => 'test',
            str_starts_with($notificationType, 'interview.') => 'interview',
            str_contains($notificationType, 'information') => 'information_request',
            str_starts_with($notificationType, 'final.'), in_array($data['status'] ?? null, ['accepted', 'rejected'], true) => 'final_decision',
            str_contains($notificationType, 'reminder') => 'application_reminder',
            default => 'application_status',
        };
        [$resourceType, $resourceId] = $this->activityResource($data);
        $actionType = match (true) {
            $notificationType === 'test.assigned', $notificationType === 'test.retake_granted' => 'start_test',
            $notificationType === 'test.evaluated' => 'view_test_result',
            in_array($notificationType, ['interview.scheduled', 'interview.rescheduled'], true) => 'confirm_interview',
            str_contains($notificationType, 'interview.') => 'view_interview',
            $notificationType === 'application.information_requested' => 'submit_information',
            str_starts_with($notificationType, 'application.'), str_starts_with($notificationType, 'final.') => 'view_application',
            default => 'none',
        };

        return array_merge($data, [
            'activity_version' => 1,
            'activity_key' => $data['activity_key'] ?? null,
            'application_id' => $applicationId,
            'job_posting_id' => $data['job_posting_id'] ?? $data['job_id'] ?? null,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'activity_type' => $activityType,
            'action_type' => $actionType,
            'occurred_at' => $data['occurred_at'] ?? now()->toISOString(),
        ]);
    }

    /** @param array<string, mixed> $data @return array{0:?string,1:?int} */
    private function activityResource(array $data): array
    {
        foreach ([
            'test_attempt_id' => 'test_attempt',
            'test_assignment_id' => 'test_assignment',
            'interview_id' => 'interview',
            'information_request_id' => 'information_request',
            'application_id' => 'application',
            'job_application_id' => 'application',
        ] as $key => $type) {
            if (is_numeric($data[$key] ?? null)) {
                return [$type, (int) $data[$key]];
            }
        }

        return [null, null];
    }
}

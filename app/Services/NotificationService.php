<?php

namespace App\Services;

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
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data === [] ? null : $data,
        ]);
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
}

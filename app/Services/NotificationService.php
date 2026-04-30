<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
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
        return Notification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public function markAsRead(int $notificationId, int $userId): Notification
    {
        $notification = Notification::query()
            ->whereKey($notificationId)
            ->where('user_id', $userId)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->refresh();
    }

    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    public function getUserNotifications(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->latest()
            ->paginate($perPage);
    }

    public function getUnreadCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}

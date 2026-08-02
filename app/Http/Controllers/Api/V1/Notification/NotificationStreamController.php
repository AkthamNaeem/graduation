<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NotificationStreamController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $user = $request->user();
        $suppliedCursor = $request->query('cursor') ?? $request->header('Last-Event-ID');
        $cursor = $suppliedCursor === null
            ? (int) Notification::query()->where('user_id', $user->id)->max('id')
            : max(0, (int) $suppliedCursor);
        $duration = max(5, min(55, (int) config('realtime_notifications.stream.duration_seconds', 25)));
        $pollMicroseconds = max(250, (int) config('realtime_notifications.stream.poll_interval_milliseconds', 1000)) * 1000;
        $batchSize = max(1, min(100, (int) config('realtime_notifications.stream.batch_size', 50)));

        return response()->stream(function () use ($user, $cursor, $duration, $pollMicroseconds, $batchSize, $request): void {
            ignore_user_abort(true);
            set_time_limit($duration + 5);

            $lastId = $cursor;
            $deadline = microtime(true) + $duration;
            $lastHeartbeat = 0.0;
            $lastUnreadCount = $this->unreadCount($user->id);

            $this->emit('connected', [
                'cursor' => $lastId,
                'unread_count' => $lastUnreadCount,
                'server_time' => now()->toISOString(),
            ]);

            while (microtime(true) < $deadline && ! connection_aborted()) {
                $notifications = Notification::query()
                    ->where('user_id', $user->id)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->get();

                foreach ($notifications as $notification) {
                    $lastId = $notification->id;
                    $resource = (new NotificationResource($notification))->toArray($request);
                    $this->emit('notification.created', $resource, $lastId);
                }

                $unreadCount = $this->unreadCount($user->id);
                if ($unreadCount !== $lastUnreadCount) {
                    $lastUnreadCount = $unreadCount;
                    $this->emit('unread-count.updated', [
                        'unread_count' => $lastUnreadCount,
                    ]);
                }

                if ((microtime(true) - $lastHeartbeat) >= 10) {
                    echo ': heartbeat '.now()->toISOString()."\n\n";
                    $lastHeartbeat = microtime(true);
                    $this->flush();
                }

                usleep($pollMicroseconds);
            }

            $this->emit('stream.closed', [
                'cursor' => $lastId,
                'reconnect_after_ms' => 1000,
            ], $lastId);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param array<string,mixed> $data */
    private function emit(string $event, array $data, ?int $id = null): void
    {
        if ($id !== null) {
            echo 'id: '.$id."\n";
        }

        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n\n";
        $this->flush();
    }

    private function unreadCount(int $userId): int
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }
}

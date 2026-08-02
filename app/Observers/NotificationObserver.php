<?php

namespace App\Observers;

use App\Jobs\SendNotificationPushJob;
use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

class NotificationObserver
{
    public function created(Notification $notification): void
    {
        if (! config('realtime_notifications.push.enabled')) {
            return;
        }

        $hasActiveDevice = DeviceToken::query()
            ->where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->exists();

        if (! $hasActiveDevice) {
            return;
        }

        $dispatch = static fn (): mixed => SendNotificationPushJob::dispatch($notification->id);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}

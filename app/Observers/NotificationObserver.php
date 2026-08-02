<?php

namespace App\Observers;

use App\Jobs\SendNotificationPushJob;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;

class NotificationObserver
{
    public function created(Notification $notification): void
    {
        $dispatch = static fn (): mixed => SendNotificationPushJob::dispatch($notification->id);

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}

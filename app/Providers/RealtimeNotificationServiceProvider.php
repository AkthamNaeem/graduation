<?php

namespace App\Providers;

use App\Http\Controllers\Api\V1\Notification\DeviceTokenController;
use App\Http\Controllers\Api\V1\Notification\NotificationStreamController;
use App\Models\Notification;
use App\Observers\NotificationObserver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RealtimeNotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Notification::observe(NotificationObserver::class);

        Route::middleware(['api', 'auth:sanctum', 'user.active'])
            ->prefix('api/v1')
            ->group(function (): void {
                Route::get('notifications/stream', NotificationStreamController::class)
                    ->name('notifications.stream');
                Route::post('device-tokens', [DeviceTokenController::class, 'store'])
                    ->middleware('throttle:30,1')
                    ->name('device-tokens.store');
                Route::delete('device-tokens/{deviceToken}', [DeviceTokenController::class, 'destroy'])
                    ->name('device-tokens.destroy');
            });
    }
}

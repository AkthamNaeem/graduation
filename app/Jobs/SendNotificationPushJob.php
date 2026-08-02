<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\PushDelivery;
use App\Services\Push\FirebaseCloudMessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendNotificationPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 55;

    public bool $failOnTimeout = true;

    /** @var array<int,int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('notifications');
    }

    public function handle(FirebaseCloudMessagingService $fcm): void
    {
        if (! config('realtime_notifications.push.enabled')) {
            return;
        }

        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }

        DeviceToken::query()
            ->where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->eachById(function (DeviceToken $deviceToken) use ($notification, $fcm): void {
                $delivery = PushDelivery::query()->firstOrCreate(
                    [
                        'notification_id' => $notification->id,
                        'device_token_id' => $deviceToken->id,
                    ],
                    ['provider' => 'fcm', 'status' => 'pending'],
                );

                if ($delivery->status === 'sent') {
                    return;
                }

                try {
                    $result = $fcm->send($deviceToken, $notification);
                    $delivery->forceFill([
                        'status' => $result['token_invalid'] ? 'token_invalid' : 'sent',
                        'provider_message_id' => $result['message_id'],
                        'attempts' => $delivery->attempts + 1,
                        'last_error' => $result['token_invalid'] ? 'FCM rejected the device token.' : null,
                        'sent_at' => $result['token_invalid'] ? null : now(),
                        'failed_at' => $result['token_invalid'] ? now() : null,
                    ])->save();

                    if ($result['token_invalid']) {
                        $deviceToken->forceFill([
                            'is_active' => false,
                            'disabled_at' => now(),
                        ])->save();
                    }
                } catch (Throwable $exception) {
                    $safeError = $this->safeError($exception, $deviceToken);
                    $delivery->forceFill([
                        'status' => 'failed',
                        'attempts' => $delivery->attempts + 1,
                        'last_error' => $safeError,
                        'failed_at' => now(),
                    ])->save();

                    throw new RuntimeException($safeError);
                }
            });
    }

    private function safeError(Throwable $exception, DeviceToken $deviceToken): string
    {
        $message = str_replace($deviceToken->token, '[redacted-device-token]', $exception->getMessage());
        $safeMessages = [
            'FCM_PROJECT_ID is not configured.',
            'FCM service-account credentials are not configured correctly.',
            'FCM OAuth response did not contain an access token.',
            'Unable to connect to FCM.',
            'Unable to sign the FCM service-account assertion.',
        ];

        if (in_array($message, $safeMessages, true)
            || preg_match('/\AFCM request failed with status [A-Z0-9_]+\.\z/', $message) === 1) {
            return $message;
        }

        return 'Push delivery failed.';
    }
}

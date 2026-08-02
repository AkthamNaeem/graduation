<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserStatus;
use App\Jobs\SendNotificationPushJob;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use App\Services\Push\FirebaseCloudMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class RealtimeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_refresh_and_disable_a_device_token(): void
    {
        $user = $this->activeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-1',
            'platform' => 'android',
            'device_id' => 'pixel-1',
            'app_version' => '1.0.0',
            'locale' => 'ar',
        ])->assertCreated()
            ->assertJsonMissing(['token' => 'fcm-token-1']);

        $deviceTokenId = $response->json('data.id');

        $this->postJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-1',
            'platform' => 'android',
            'device_id' => 'pixel-1',
            'app_version' => '1.0.1',
            'locale' => 'en',
        ])->assertOk();

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', [
            'id' => $deviceTokenId,
            'user_id' => $user->id,
            'is_active' => true,
            'app_version' => '1.0.1',
            'locale' => 'en',
        ]);

        $this->deleteJson("/api/v1/device-tokens/{$deviceTokenId}")
            ->assertOk();

        $this->assertDatabaseHas('device_tokens', [
            'id' => $deviceTokenId,
            'is_active' => false,
        ]);
    }

    public function test_registering_an_existing_token_transfers_it_to_the_current_user_without_duplication(): void
    {
        $previousUser = $this->activeUser();
        $currentUser = $this->activeUser();
        $deviceToken = $this->deviceToken($previousUser, 'shared-installation-token');
        Sanctum::actingAs($currentUser);

        $this->postJson('/api/v1/device-tokens', [
            'token' => 'shared-installation-token',
            'platform' => 'ios',
            'device_id' => 'same-phone',
        ])->assertOk();

        $this->assertDatabaseCount('device_tokens', 1);
        $this->assertDatabaseHas('device_tokens', [
            'id' => $deviceToken->id,
            'user_id' => $currentUser->id,
            'platform' => 'ios',
            'device_id' => 'same-phone',
            'is_active' => true,
        ]);
    }

    public function test_user_cannot_disable_another_users_device_token(): void
    {
        $owner = $this->activeUser();
        $other = $this->activeUser();
        $deviceToken = $this->deviceToken($owner, 'private-token', 'ios');
        Sanctum::actingAs($other);

        $this->deleteJson("/api/v1/device-tokens/{$deviceToken->id}")
            ->assertNotFound();

        $this->assertTrue($deviceToken->fresh()->is_active);
    }

    public function test_push_job_is_not_dispatched_when_push_is_disabled(): void
    {
        Queue::fake();
        config()->set('realtime_notifications.push.enabled', false);
        $user = $this->activeUser();
        $this->deviceToken($user, 'active-token');

        $this->notification($user);

        Queue::assertNothingPushed();
    }

    public function test_push_job_is_not_dispatched_without_an_active_device(): void
    {
        Queue::fake();
        config()->set('realtime_notifications.push.enabled', true);
        $user = $this->activeUser();
        $this->deviceToken($user, 'disabled-token', isActive: false);

        $this->notification($user);

        Queue::assertNothingPushed();
    }

    public function test_creating_a_notification_dispatches_a_bounded_job_to_the_notifications_queue(): void
    {
        Queue::fake();
        config()->set('realtime_notifications.push.enabled', true);
        $user = $this->activeUser();
        $this->deviceToken($user, 'active-token');

        $notification = $this->notification($user);

        Queue::assertPushed(SendNotificationPushJob::class, function (SendNotificationPushJob $job) use ($notification): bool {
            return $job->notificationId === $notification->id
                && $job->queue === 'notifications'
                && $job->tries === 4
                && $job->timeout === 55
                && $job->backoff === [10, 60, 300];
        });
    }

    public function test_push_dispatch_waits_for_the_database_transaction_to_commit(): void
    {
        Queue::fake();
        config()->set('realtime_notifications.push.enabled', true);
        $user = $this->activeUser();
        $this->deviceToken($user, 'transaction-token');

        DB::beginTransaction();

        try {
            $notification = $this->notification($user);
            Queue::assertNothingPushed();

            DB::commit();

            Queue::assertPushed(
                SendNotificationPushJob::class,
                fn (SendNotificationPushJob $job): bool => $job->notificationId === $notification->id,
            );
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_push_delivery_is_idempotent_for_the_same_notification_and_device(): void
    {
        config()->set('realtime_notifications.push.enabled', false);
        $user = $this->activeUser();
        $this->deviceToken($user, 'idempotent-token');
        $notification = $this->notification($user);
        $this->fakeFcm(['name' => 'projects/example/messages/1']);
        config()->set('realtime_notifications.push.enabled', true);

        $job = new SendNotificationPushJob($notification->id);
        $job->handle(app(FirebaseCloudMessagingService::class));
        $job->handle(app(FirebaseCloudMessagingService::class));

        $this->assertDatabaseCount('push_deliveries', 1);
        $this->assertDatabaseHas('push_deliveries', [
            'notification_id' => $notification->id,
            'status' => 'sent',
            'attempts' => 1,
            'provider_message_id' => 'projects/example/messages/1',
        ]);
        Http::assertSentCount(1);
    }

    public function test_firebase_invalid_token_is_disabled_without_retrying(): void
    {
        config()->set('realtime_notifications.push.enabled', false);
        $user = $this->activeUser();
        $deviceToken = $this->deviceToken($user, 'unregistered-token');
        $notification = $this->notification($user);
        $this->fakeFcm([
            'error' => [
                'status' => 'NOT_FOUND',
                'details' => [['errorCode' => 'UNREGISTERED']],
            ],
        ], 404);
        config()->set('realtime_notifications.push.enabled', true);

        (new SendNotificationPushJob($notification->id))
            ->handle(app(FirebaseCloudMessagingService::class));

        $this->assertFalse($deviceToken->fresh()->is_active);
        $this->assertDatabaseHas('push_deliveries', [
            'notification_id' => $notification->id,
            'device_token_id' => $deviceToken->id,
            'status' => 'token_invalid',
            'attempts' => 1,
        ]);
        $this->assertNotNull($deviceToken->pushDeliveries()->firstOrFail()->failed_at);
    }

    public function test_transient_firebase_failure_does_not_disable_device_or_lose_notification(): void
    {
        config()->set('realtime_notifications.push.enabled', true);
        $user = $this->activeUser();
        $deviceToken = $this->deviceToken($user, 'private-transient-token');
        $this->fakeFcm([
            'error' => [
                'status' => 'UNAVAILABLE',
                'message' => 'provider detail that must not be persisted',
            ],
        ], 503);

        $notification = $this->notification($user);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertTrue($deviceToken->fresh()->is_active);
        $delivery = $deviceToken->pushDeliveries()->firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('FCM request failed with status UNAVAILABLE.', $delivery->last_error);
        $this->assertNotNull($delivery->failed_at);
        $this->assertStringNotContainsString($deviceToken->token, $delivery->last_error);
        $this->assertStringNotContainsString('provider detail', $delivery->last_error);
    }

    public function test_untrusted_provider_exception_cannot_persist_or_rethrow_the_device_token(): void
    {
        config()->set('realtime_notifications.push.enabled', false);
        $user = $this->activeUser();
        $deviceToken = $this->deviceToken($user, 'must-never-leak-token');
        $notification = $this->notification($user);
        config()->set('realtime_notifications.push.enabled', true);
        $fcm = $this->mock(FirebaseCloudMessagingService::class);
        $fcm->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('Provider echoed must-never-leak-token'));

        $caught = null;
        try {
            (new SendNotificationPushJob($notification->id))->handle($fcm);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Push delivery failed.', $caught->getMessage());
        $this->assertSame('Push delivery failed.', $deviceToken->pushDeliveries()->firstOrFail()->last_error);
    }

    public function test_push_payload_only_contains_allowlisted_string_data(): void
    {
        config()->set('realtime_notifications.push.enabled', false);
        $user = $this->activeUser();
        $deviceToken = $this->deviceToken($user, 'payload-token');
        $notification = $this->notification($user, [
            'application_id' => 42,
            'resource_type' => 'application',
            'internal_note' => 'private company note',
            'internal_score' => 13,
            'otp' => '123456',
            'secret' => 'hidden',
        ]);
        $this->fakeFcm(['name' => 'projects/example/messages/2']);
        config()->set('realtime_notifications.push.enabled', true);

        app(FirebaseCloudMessagingService::class)->send($deviceToken, $notification);

        Http::assertSent(function (ClientRequest $request) use ($notification): bool {
            $data = $request->data()['message']['data'];

            return $data['application_id'] === '42'
                && $data['resource_type'] === 'application'
                && $data['notification_id'] === (string) $notification->id
                && $data['notification_type'] === $notification->type
                && ! array_key_exists('internal_note', $data)
                && ! array_key_exists('internal_score', $data)
                && ! array_key_exists('otp', $data)
                && ! array_key_exists('secret', $data);
        });
    }

    public function test_realtime_and_device_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/device-tokens', [
            'token' => 'anonymous-token',
            'platform' => 'web',
        ])->assertUnauthorized();

        $this->withHeader('Accept', 'text/event-stream')
            ->get('/api/v1/notifications/stream')
            ->assertUnauthorized();
    }

    public function test_realtime_stream_only_emits_the_authenticated_users_notifications(): void
    {
        config()->set('realtime_notifications.stream.duration_seconds', 1);
        config()->set('realtime_notifications.stream.poll_interval_milliseconds', 250);
        $user = $this->activeUser();
        $other = $this->activeUser();
        $otherNotification = $this->notification($other, title: 'Other user private notification');
        $ownNotification = $this->notification($user, title: 'Current user notification');
        Sanctum::actingAs($user);
        $executionLimit = (int) ini_get('max_execution_time');

        $response = $this->withHeader('Accept', 'text/event-stream')
            ->get('/api/v1/notifications/stream?cursor=0')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');
        $content = $response->streamedContent();

        $this->assertStringContainsString('id: '.$ownNotification->id, $content);
        $this->assertStringContainsString('Current user notification', $content);
        $this->assertStringNotContainsString('id: '.$otherNotification->id."\n", $content);
        $this->assertStringNotContainsString('Other user private notification', $content);
        $this->assertSame($executionLimit, (int) ini_get('max_execution_time'));
    }

    public function test_realtime_stream_without_cursor_starts_after_the_latest_notification(): void
    {
        config()->set('realtime_notifications.stream.duration_seconds', 1);
        config()->set('realtime_notifications.stream.poll_interval_milliseconds', 250);
        $user = $this->activeUser();
        $historical = $this->notification($user, title: 'Historical notification');
        Sanctum::actingAs($user);

        $content = $this->withHeader('Accept', 'text/event-stream')
            ->get('/api/v1/notifications/stream')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('"cursor":'.$historical->id, $content);
        $this->assertStringNotContainsString('event: notification.created', $content);
        $this->assertStringNotContainsString('Historical notification', $content);
    }

    public function test_realtime_stream_replays_newer_events_in_order_after_cursor(): void
    {
        config()->set('realtime_notifications.stream.duration_seconds', 1);
        config()->set('realtime_notifications.stream.poll_interval_milliseconds', 250);
        $user = $this->activeUser();
        $cursor = $this->notification($user, title: 'Already received');
        $second = $this->notification($user, title: 'Second event');
        $third = $this->notification($user, title: 'Third event');
        Sanctum::actingAs($user);

        $content = $this->withHeader('Accept', 'text/event-stream')
            ->withHeader('Last-Event-ID', (string) $cursor->id)
            ->get('/api/v1/notifications/stream')
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('Already received', $content);
        $this->assertStringContainsString('id: '.$second->id, $content);
        $this->assertStringContainsString('id: '.$third->id, $content);
        $this->assertLessThan(strpos($content, 'Third event'), strpos($content, 'Second event'));
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => UserStatus::ACTIVE]);
    }

    private function deviceToken(
        User $user,
        string $token,
        string $platform = 'android',
        bool $isActive = true,
    ): DeviceToken {
        return DeviceToken::query()->create([
            'user_id' => $user->id,
            'token' => $token,
            'platform' => $platform,
            'is_active' => $isActive,
            'disabled_at' => $isActive ? null : now(),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function notification(User $user, array $data = [], string $title = 'Application updated'): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'application.status_changed',
            'title' => $title,
            'message' => 'Your application status changed.',
            'data' => $data === [] ? null : $data,
        ]);
    }

    /** @param array<string,mixed> $response */
    private function fakeFcm(array $response, int $status = 200): void
    {
        config()->set('realtime_notifications.push.project_id', 'test-project');
        Cache::put('fcm.oauth_access_token', 'fake-access-token', now()->addMinutes(5));
        Http::fake([
            'https://fcm.googleapis.com/*' => Http::response($response, $status),
        ]);
    }
}

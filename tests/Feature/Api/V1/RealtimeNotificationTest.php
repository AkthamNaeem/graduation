<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendNotificationPushJob;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_register_refresh_and_disable_a_device_token(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/device-tokens', [
            'token' => 'fcm-token-1',
            'platform' => 'android',
            'device_id' => 'pixel-1',
            'app_version' => '1.0.0',
            'locale' => 'ar',
        ])->assertCreated();

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

    public function test_user_cannot_disable_another_users_device_token(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $deviceToken = DeviceToken::query()->create([
            'user_id' => $owner->id,
            'token' => 'private-token',
            'platform' => 'ios',
            'is_active' => true,
        ]);

        Sanctum::actingAs($other);

        $this->deleteJson("/api/v1/device-tokens/{$deviceToken->id}")
            ->assertNotFound();

        $this->assertTrue($deviceToken->fresh()->is_active);
    }

    public function test_creating_any_notification_dispatches_the_push_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'application.status_changed',
            'title' => 'Application updated',
            'message' => 'Your application status changed.',
        ]);

        Queue::assertPushed(
            SendNotificationPushJob::class,
            fn (SendNotificationPushJob $job): bool => $job->notificationId === $notification->id,
        );
    }

    public function test_realtime_and_device_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/device-tokens', [
            'token' => 'anonymous-token',
            'platform' => 'web',
        ])->assertUnauthorized();

        $this->get('/api/v1/notifications/stream')
            ->assertUnauthorized();
    }
}

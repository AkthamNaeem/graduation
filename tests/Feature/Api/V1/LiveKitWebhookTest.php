<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Interview;
use App\Models\InterviewVideoSession;
use Carbon\CarbonImmutable;
use Database\Seeders\ApplicationStatusSeeder;
use Firebase\JWT\JWT;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\V1\Concerns\CreatesInterviewScenarios;
use Tests\TestCase;

class LiveKitWebhookTest extends TestCase
{
    use CreatesInterviewScenarios;
    use RefreshDatabase;

    private const API_KEY = 'webhook-test-key';

    private const API_SECRET = 'webhook-test-secret-with-sufficient-length';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        config([
            'services.livekit.enabled' => true,
            'services.livekit.api_key' => self::API_KEY,
            'services.livekit.api_secret' => self::API_SECRET,
        ]);
    }

    public function test_signed_webhooks_update_only_idempotent_operational_timestamps(): void
    {
        CarbonImmutable::setTestNow('2026-08-08 12:00:00');
        [$employer, , $application] = $this->interviewScenario();
        $interviewId = $this->createInterview($employer, $application, [
            'video_provider' => 'livekit',
        ]);
        $session = InterviewVideoSession::query()->where('interview_id', $interviewId)->firstOrFail();
        $interviewStatus = Interview::findOrFail($interviewId)->status;
        $applicationStatus = $application->fresh()->application_status_id;

        $joinedBody = $this->eventBody('participant_joined', 'event-joined', $session->room_name, 1786190400);
        $this->postWebhook($joinedBody)->assertOk();
        $this->postWebhook($joinedBody)->assertOk();
        $this->assertDatabaseCount('livekit_webhook_events', 1);
        $this->assertNotNull($session->fresh()->first_joined_at);
        $this->assertSame($interviewStatus, Interview::findOrFail($interviewId)->status);
        $this->assertSame($applicationStatus, $application->fresh()->application_status_id);

        $finishedBody = $this->eventBody('room_finished', 'event-finished', $session->room_name, 1786194000);
        $this->postWebhook($finishedBody)->assertOk();
        $this->postWebhook($finishedBody)->assertOk();
        $this->assertDatabaseCount('livekit_webhook_events', 2);
        $this->assertNotNull($session->fresh()->room_ended_at);
        $this->assertSame($interviewStatus, Interview::findOrFail($interviewId)->status);
        $this->assertSame($applicationStatus, $application->fresh()->application_status_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'interview.livekit_room_finished')->count());
    }

    public function test_invalid_signature_is_rejected_without_persistence(): void
    {
        $body = $this->eventBody('room_started', 'event-invalid', 'unknown-room', time());

        $this->call('POST', '/api/v1/webhooks/livekit', [], [], [], [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token',
        ], $body)
            ->assertStatus(401)
            ->assertJsonPath('code', 'LIVEKIT_WEBHOOK_INVALID');

        $this->assertDatabaseCount('livekit_webhook_events', 0);
    }

    public function test_unknown_rooms_and_unobserved_events_are_acknowledged_without_side_effects(): void
    {
        $unknown = $this->eventBody('room_started', 'unknown-room-event', 'not-a-workey-room', time());
        $this->postWebhook($unknown)->assertOk();

        $ignored = $this->eventBody('track_published', 'ignored-event', 'not-a-workey-room', time());
        $this->postWebhook($ignored)->assertOk();

        $this->assertDatabaseCount('livekit_webhook_events', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'interview.livekit_room_started']);
    }

    public function test_migration_resumes_after_mysql_style_partial_table_creation(): void
    {
        Schema::table('livekit_webhook_events', function (Blueprint $table): void {
            $table->dropIndex('lk_webhook_session_type_idx');
        });
        $this->assertFalse(Schema::hasIndex(
            'livekit_webhook_events',
            ['interview_video_session_id', 'event_type'],
        ));

        $migration = require database_path('migrations/2026_08_08_000001_create_interview_video_sessions_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('interview_video_sessions'));
        $this->assertTrue(Schema::hasTable('livekit_webhook_events'));
        $this->assertTrue(Schema::hasIndex(
            'livekit_webhook_events',
            ['interview_video_session_id', 'event_type'],
        ));
    }

    private function postWebhook(string $body): TestResponse
    {
        $now = time();
        $token = JWT::encode([
            'iss' => self::API_KEY,
            'nbf' => $now - 5,
            'iat' => $now - 5,
            'exp' => $now + 60,
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ], self::API_SECRET, 'HS256');

        return $this->call('POST', '/api/v1/webhooks/livekit', [], [], [], [
            'CONTENT_TYPE' => 'application/webhook+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], $body);
    }

    private function eventBody(string $event, string $id, string $roomName, int $createdAt): string
    {
        return json_encode([
            'event' => $event,
            'id' => $id,
            'createdAt' => $createdAt,
            'room' => ['name' => $roomName],
            'participant' => ['identity' => 'workey-user-999'],
        ], JSON_THROW_ON_ERROR);
    }
}

<?php

namespace Tests\Feature\Api\V1;

use App\Contracts\InterviewVideoTokenProvider;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\EmployerProfile;
use App\Models\Interview;
use App\Models\InterviewVideoSession;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ApplicationStatusSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Api\V1\Concerns\CreatesInterviewScenarios;
use Tests\TestCase;

class InterviewVideoTest extends TestCase
{
    use CreatesInterviewScenarios;
    use RefreshDatabase;

    private const API_KEY = 'test-livekit-key';

    private const API_SECRET = 'test-livekit-secret-with-sufficient-length';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ApplicationStatusSeeder::class);
        $this->configureLiveKit();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_livekit_enablement_is_additive_and_online_only(): void
    {
        [$employer, , $application] = $this->interviewScenario();

        $manualId = $this->createInterview($employer, $application);
        $this->assertDatabaseMissing('interview_video_sessions', ['interview_id' => $manualId]);
        $this->withToken($this->tokenForInterviewUser($employer))
            ->getJson("/api/v1/interviews/{$manualId}")
            ->assertOk()
            ->assertJsonPath('data.meeting_link', 'https://meet.example.com/technical')
            ->assertJsonPath('data.video_provider', null)
            ->assertJsonPath('data.embedded_video_available', false);

        $liveKitId = $this->createInterview($employer, $application, [
            'type' => 'hr',
            'video_provider' => 'livekit',
            'meeting_link' => null,
        ]);
        $session = InterviewVideoSession::query()->where('interview_id', $liveKitId)->firstOrFail();
        $this->assertSame('livekit', $session->provider);
        $this->assertTrue($session->enabled);
        $this->assertMatchesRegularExpression('/^workey-interview-[0-9a-f-]{36}$/', $session->room_name);
        $this->withToken($this->tokenForInterviewUser($employer))
            ->getJson("/api/v1/interviews/{$liveKitId}")
            ->assertOk()
            ->assertJsonPath('data.video_provider', 'livekit')
            ->assertJsonPath('data.embedded_video_available', true)
            ->assertJsonMissingPath('data.room')
            ->assertJsonMissingPath('data.participant_token')
            ->assertJsonMissingPath('data.server_url');

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/applications/{$application->id}/interviews", $this->validInterviewPayload([
                'type' => 'final',
                'mode' => 'on_site',
                'location_text' => 'Damascus HQ',
                'meeting_link' => null,
                'video_provider' => 'livekit',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'INTERVIEW_VIDEO_NOT_AVAILABLE');
        $this->assertDatabaseCount('interview_video_sessions', 1);
    }

    public function test_candidate_and_employer_receive_secure_tokens_for_the_same_room(): void
    {
        CarbonImmutable::setTestNow('2026-08-08 12:00:00');
        [$employer, $candidate, $application] = $this->interviewScenario();
        $interviewId = $this->createJoinableLiveKitInterview($employer, $application->id);

        $candidateResponse = $this->withToken($this->tokenForInterviewUser($candidate))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertOk()
            ->assertJsonPath('data.provider', 'livekit')
            ->assertJsonPath('data.server_url', 'wss://test.livekit.cloud')
            ->assertJsonPath('data.participant.identity', "workey-user-{$candidate->id}")
            ->assertJsonPath('data.participant.role', 'candidate')
            ->assertJsonPath('data.fallback_meeting_link', 'https://meet.example.com/fallback');

        $employerResponse = $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertOk()
            ->assertJsonPath('data.participant.identity', "workey-user-{$employer->id}")
            ->assertJsonPath('data.participant.role', 'employer');

        $this->assertSame($candidateResponse->json('data.room.name'), $employerResponse->json('data.room.name'));
        $claims = (array) JWT::decode($candidateResponse->json('data.participant_token'), new Key(self::API_SECRET, 'HS256'));
        $grant = (array) $claims['video'];
        $this->assertSame(self::API_KEY, $claims['iss']);
        $this->assertSame("workey-user-{$candidate->id}", $claims['sub']);
        $this->assertSame(900, $claims['exp'] - $claims['iat']);
        $this->assertTrue($grant['roomJoin']);
        $this->assertTrue($grant['canPublish']);
        $this->assertTrue($grant['canSubscribe']);
        $this->assertSame($candidateResponse->json('data.room.name'), $grant['room']);
        $this->assertArrayNotHasKey('roomAdmin', $grant);
        $this->assertArrayNotHasKey('roomRecord', $grant);
        $this->assertDatabaseCount('interview_video_sessions', 1);
        $this->assertDatabaseMissing('interview_video_sessions', ['room_name' => $candidateResponse->json('data.participant_token')]);
        $this->assertStringNotContainsString(self::API_SECRET, $candidateResponse->getContent());
    }

    public function test_video_session_authorization_rejects_unrelated_users_admins_and_client_identity(): void
    {
        CarbonImmutable::setTestNow('2026-08-08 12:00:00');
        [$employer, , $application] = $this->interviewScenario();
        $interviewId = $this->createJoinableLiveKitInterview($employer, $application->id);

        $unrelatedCandidate = User::factory()->create(['role' => UserRole::JOB_SEEKER]);
        JobSeekerProfile::create(['user_id' => $unrelatedCandidate->id]);
        $this->withToken($this->tokenForInterviewUser($unrelatedCandidate))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertForbidden();

        $otherCompany = Company::create(['name' => 'Other Company', 'approval_status' => 'approved']);
        $otherEmployer = User::factory()->create(['role' => UserRole::EMPLOYER]);
        EmployerProfile::create(['user_id' => $otherEmployer->id, 'company_id' => $otherCompany->id]);
        $this->withToken($this->tokenForInterviewUser($otherEmployer))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->withToken($this->tokenForInterviewUser($admin))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertForbidden();

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session", [
                'participant_identity' => 'attacker-controlled',
                'room_name' => 'another-room',
            ])
            ->assertUnprocessable();
    }

    public function test_join_window_and_terminal_status_rules_are_enforced(): void
    {
        CarbonImmutable::setTestNow('2026-08-08 12:00:00');
        [$employer, , $application] = $this->interviewScenario();
        $interviewId = $this->createJoinableLiveKitInterview($employer, $application->id, 60, 120);
        $token = $this->tokenForInterviewUser($employer);

        $this->withToken($token)->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertStatus(409)->assertJsonPath('code', 'INTERVIEW_VIDEO_TOO_EARLY');

        Interview::query()->whereKey($interviewId)->update([
            'scheduled_at' => now()->addMinutes(10),
            'scheduled_end_at' => now()->addMinutes(70),
        ]);
        $this->withToken($token)->postJson("/api/v1/interviews/{$interviewId}/video-session")->assertOk();

        Interview::query()->whereKey($interviewId)->update([
            'scheduled_at' => now()->subMinutes(10),
            'scheduled_end_at' => now()->addMinutes(50),
        ]);
        $this->withToken($token)->postJson("/api/v1/interviews/{$interviewId}/video-session")->assertOk();

        Interview::query()->whereKey($interviewId)->update([
            'scheduled_at' => now()->subHours(2),
            'scheduled_end_at' => now()->subMinutes(31),
        ]);
        $this->withToken($token)->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertStatus(409)->assertJsonPath('code', 'INTERVIEW_VIDEO_WINDOW_CLOSED');

        Interview::query()->whereKey($interviewId)->update(['status' => 'cancelled']);
        $this->withToken($token)->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertStatus(409)->assertJsonPath('code', 'INTERVIEW_VIDEO_NOT_JOINABLE');
    }

    public function test_reschedule_preserves_room_and_uses_the_new_join_window(): void
    {
        CarbonImmutable::setTestNow('2026-08-08 12:00:00');
        [$employer, , $application] = $this->interviewScenario();
        $interviewId = $this->createJoinableLiveKitInterview($employer, $application->id, 60, 120);
        $room = InterviewVideoSession::query()->where('interview_id', $interviewId)->value('room_name');
        $newStart = CarbonImmutable::now()->addDays(2);
        $newEnd = $newStart->addHour();

        config(['services.livekit.enabled' => false]);
        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/reschedule", [
                'mode' => 'online',
                'scheduled_start_at' => $newStart->toISOString(),
                'scheduled_end_at' => $newEnd->toISOString(),
                'meeting_link' => null,
                'reason' => 'Panel availability changed.',
            ])->assertOk();

        $this->assertSame($room, InterviewVideoSession::query()->where('interview_id', $interviewId)->value('room_name'));
        $this->configureLiveKit();
        CarbonImmutable::setTestNow($newStart->subMinutes(10));
        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertOk()
            ->assertJsonPath('data.room.name', $room);
    }

    public function test_livekit_unavailability_does_not_block_local_cancellation(): void
    {
        CarbonImmutable::setTestNow('2026-08-08 12:00:00');
        [$employer, , $application] = $this->interviewScenario();
        $interviewId = $this->createJoinableLiveKitInterview($employer, $application->id, 60, 120);
        config(['services.livekit.enabled' => false]);

        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/cancel", [
                'reason' => 'Panel unavailable.',
                'candidate_message' => 'The interview was cancelled.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status.key', 'cancelled');

        $this->assertDatabaseHas('interviews', ['id' => $interviewId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('interview_video_sessions', ['interview_id' => $interviewId, 'enabled' => true]);
    }

    public function test_missing_configuration_and_token_failure_are_safe(): void
    {
        CarbonImmutable::setTestNow('2026-08-08 12:00:00');
        $this->app->bind(InterviewVideoTokenProvider::class, fn () => new class implements InterviewVideoTokenProvider
        {
            public function issueParticipantToken(string $apiKey, string $apiSecret, string $roomName, string $participantIdentity, string $participantName, int $ttlSeconds): string
            {
                throw new RuntimeException('provider details must not leak');
            }
        });
        [$employer, , $application] = $this->interviewScenario();
        $interviewId = $this->createJoinableLiveKitInterview($employer, $application->id);
        $originalStatus = Interview::findOrFail($interviewId)->status;
        $applicationStatus = $application->fresh()->application_status_id;

        config(['services.livekit.enabled' => false]);
        $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertStatus(503)
            ->assertJsonPath('code', 'LIVEKIT_NOT_CONFIGURED');

        $this->configureLiveKit();
        $response = $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/interviews/{$interviewId}/video-session")
            ->assertStatus(503)
            ->assertJsonPath('code', 'LIVEKIT_TOKEN_GENERATION_FAILED');
        $this->assertStringNotContainsString('provider details', $response->getContent());
        $this->assertSame($originalStatus, Interview::findOrFail($interviewId)->status);
        $this->assertSame($applicationStatus, $application->fresh()->application_status_id);
    }

    private function configureLiveKit(): void
    {
        config([
            'services.livekit.enabled' => true,
            'services.livekit.url' => 'wss://test.livekit.cloud',
            'services.livekit.api_key' => self::API_KEY,
            'services.livekit.api_secret' => self::API_SECRET,
            'services.livekit.join_early_minutes' => 15,
            'services.livekit.join_late_minutes' => 30,
            'services.livekit.token_ttl_seconds' => 900,
        ]);
    }

    private function createJoinableLiveKitInterview(User $employer, int $applicationId, int $startsInMinutes = 10, int $endsInMinutes = 70): int
    {
        return (int) $this->withToken($this->tokenForInterviewUser($employer))
            ->postJson("/api/v1/applications/{$applicationId}/interviews", $this->validInterviewPayload([
                'scheduled_start_at' => now()->addMinutes($startsInMinutes)->toISOString(),
                'scheduled_end_at' => now()->addMinutes($endsInMinutes)->toISOString(),
                'meeting_link' => 'https://meet.example.com/fallback',
                'video_provider' => 'livekit',
            ]))
            ->assertCreated()
            ->json('data.id');
    }
}

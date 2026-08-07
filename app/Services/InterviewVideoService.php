<?php

namespace App\Services;

use App\Contracts\InterviewVideoTokenProvider;
use App\Enums\UserRole;
use App\Exceptions\InterviewLifecycleException;
use App\Models\Interview;
use App\Models\InterviewVideoSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class InterviewVideoService
{
    private const JOINABLE_STATUSES = ['scheduled', 'confirmed', 'rescheduled'];

    public function __construct(
        private readonly InterviewVideoTokenProvider $tokenProvider,
        private readonly CompanyRecruitmentAccessService $companyAccessService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function enableLiveKit(User $actor, Interview $interview): InterviewVideoSession
    {
        if ($interview->interview_mode !== 'online') {
            $this->fail('INTERVIEW_VIDEO_NOT_AVAILABLE', 422);
        }

        $session = InterviewVideoSession::query()->firstOrCreate(
            ['interview_id' => $interview->id],
            [
                'provider' => 'livekit',
                'room_name' => 'workey-interview-'.Str::uuid()->toString(),
                'enabled' => true,
            ],
        );

        if ($session->wasRecentlyCreated) {
            $this->auditLogService->record(
                'interview.video_enabled',
                $actor,
                Interview::class,
                $interview->id,
                null,
                ['provider' => 'livekit'],
                ['application_id' => $interview->job_application_id],
            );
        }

        return $session;
    }

    /** @return array<string, mixed> */
    public function issueSession(User $actor, Interview $interview): array
    {
        $role = $this->participantRole($actor, $interview);
        $this->companyAccessService->assertRecruitmentAvailable($interview);

        $session = $interview->videoSession()->first();
        if (! $session instanceof InterviewVideoSession || ! $session->enabled || $session->provider !== 'livekit') {
            $this->fail('INTERVIEW_VIDEO_NOT_ENABLED');
        }
        if ($interview->interview_mode !== 'online') {
            $this->fail('INTERVIEW_VIDEO_NOT_AVAILABLE');
        }
        if (! in_array($interview->status, self::JOINABLE_STATUSES, true)) {
            $this->fail('INTERVIEW_VIDEO_NOT_JOINABLE');
        }
        if ($interview->scheduled_at === null || $interview->scheduled_end_at === null) {
            $this->fail('INTERVIEW_VIDEO_NOT_JOINABLE');
        }

        $configuration = $this->configuredLiveKit();
        $now = CarbonImmutable::now();
        $opensAt = CarbonImmutable::instance($interview->scheduled_at)
            ->subMinutes($configuration['join_early_minutes']);
        $closesAt = CarbonImmutable::instance($interview->scheduled_end_at)
            ->addMinutes($configuration['join_late_minutes']);

        if ($now->lt($opensAt)) {
            $this->fail('INTERVIEW_VIDEO_TOO_EARLY');
        }
        if ($now->gt($closesAt)) {
            $this->fail('INTERVIEW_VIDEO_WINDOW_CLOSED');
        }

        $identity = 'workey-user-'.$actor->id;
        $expiresAt = $now->addSeconds($configuration['token_ttl_seconds']);

        try {
            $token = $this->tokenProvider->issueParticipantToken(
                $configuration['api_key'],
                $configuration['api_secret'],
                $session->room_name,
                $identity,
                $actor->name,
                $configuration['token_ttl_seconds'],
            );
        } catch (Throwable) {
            $this->fail('LIVEKIT_TOKEN_GENERATION_FAILED', 503);
        }

        $this->auditLogService->record(
            'interview.video_session_issued',
            $actor,
            Interview::class,
            $interview->id,
            null,
            ['provider' => 'livekit', 'participant_role' => $role],
            ['application_id' => $interview->job_application_id, 'actor_id' => $actor->id],
        );

        return [
            'provider' => 'livekit',
            'server_url' => $configuration['url'],
            'participant_token' => $token,
            'room' => ['name' => $session->room_name],
            'participant' => [
                'identity' => $identity,
                'display_name' => $actor->name,
                'role' => $role,
            ],
            'expires_at' => $expiresAt->toISOString(),
            'fallback_meeting_link' => $interview->meeting_link,
        ];
    }

    /** @return array{url:string, api_key:string, api_secret:string, join_early_minutes:int, join_late_minutes:int, token_ttl_seconds:int} */
    private function configuredLiveKit(): array
    {
        $url = trim((string) config('services.livekit.url'));
        $apiKey = trim((string) config('services.livekit.api_key'));
        $apiSecret = trim((string) config('services.livekit.api_secret'));

        if (! config('services.livekit.enabled') || ! str_starts_with($url, 'wss://') || $apiKey === '' || $apiSecret === '') {
            $this->fail('LIVEKIT_NOT_CONFIGURED', 503);
        }

        return [
            'url' => $url,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'join_early_minutes' => max(0, (int) config('services.livekit.join_early_minutes', 15)),
            'join_late_minutes' => max(0, (int) config('services.livekit.join_late_minutes', 30)),
            'token_ttl_seconds' => max(60, min(900, (int) config('services.livekit.token_ttl_seconds', 900))),
        ];
    }

    private function participantRole(User $actor, Interview $interview): string
    {
        $interview->loadMissing('jobApplication.jobPosting');

        if ($actor->role === UserRole::JOB_SEEKER
            && $actor->jobSeekerProfile?->id === $interview->jobApplication->job_seeker_profile_id) {
            return 'candidate';
        }

        if ($actor->role === UserRole::EMPLOYER && $actor->can('view', $interview)) {
            return 'employer';
        }

        $this->fail('INTERVIEW_VIDEO_ACCESS_DENIED', 403);
    }

    private function fail(string $code, int $status = 409): never
    {
        throw new InterviewLifecycleException(__('domain_errors.'.$code), $code, $status);
    }
}

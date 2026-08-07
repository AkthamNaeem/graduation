<?php

namespace App\Services;

use Agence104\LiveKit\WebhookReceiver;
use App\Exceptions\InterviewLifecycleException;
use App\Models\Interview;
use App\Models\InterviewVideoSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class LiveKitWebhookService
{
    private const OBSERVED_EVENTS = [
        'room_started',
        'room_finished',
        'participant_joined',
        'participant_left',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function process(string $rawBody, ?string $authorization): void
    {
        [$apiKey, $apiSecret] = $this->credentials();
        $token = preg_replace('/^Bearer\s+/i', '', trim((string) $authorization));

        try {
            $event = (new WebhookReceiver($apiKey, $apiSecret))->receive($rawBody, $token ?: null);
        } catch (Throwable) {
            throw new InterviewLifecycleException(
                __('domain_errors.LIVEKIT_WEBHOOK_INVALID'),
                'LIVEKIT_WEBHOOK_INVALID',
                401,
            );
        }

        $eventType = $event->getEvent();
        $roomName = $event->getRoom()?->getName();
        if (! in_array($eventType, self::OBSERVED_EVENTS, true) || ! is_string($roomName) || $roomName === '') {
            return;
        }

        $sessionId = InterviewVideoSession::query()
            ->where('provider', 'livekit')
            ->where('room_name', $roomName)
            ->value('id');
        if ($sessionId === null) {
            return;
        }

        $eventId = trim($event->getId()) ?: hash('sha256', $rawBody);
        $occurredAt = ((int) $event->getCreatedAt()) > 0
            ? CarbonImmutable::createFromTimestampUTC((int) $event->getCreatedAt())
            : CarbonImmutable::now();

        DB::transaction(function () use ($sessionId, $eventId, $eventType, $occurredAt): void {
            $inserted = DB::table('livekit_webhook_events')->insertOrIgnore([
                'interview_video_session_id' => $sessionId,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'processed_at' => now(),
            ]);
            if ($inserted === 0) {
                return;
            }

            $session = InterviewVideoSession::query()->lockForUpdate()->findOrFail($sessionId);
            match ($eventType) {
                'room_started' => $session->room_started_at ??= $occurredAt,
                'room_finished' => $session->room_ended_at = $this->laterOf($session->room_ended_at, $occurredAt),
                'participant_joined' => $session->first_joined_at ??= $occurredAt,
                'participant_left' => $session->last_left_at = $this->laterOf($session->last_left_at, $occurredAt),
            };
            $session->save();

            if (in_array($eventType, ['room_started', 'room_finished'], true)) {
                $this->auditLogService->record(
                    'interview.livekit_'.$eventType,
                    null,
                    Interview::class,
                    $session->interview_id,
                    null,
                    ['provider' => 'livekit', 'occurred_at' => $occurredAt->toISOString()],
                    ['livekit_event_id' => $eventId],
                );
            }
        });
    }

    /** @return array{string, string} */
    private function credentials(): array
    {
        $apiKey = trim((string) config('services.livekit.api_key'));
        $apiSecret = trim((string) config('services.livekit.api_secret'));
        if (! config('services.livekit.enabled') || $apiKey === '' || $apiSecret === '') {
            throw new InterviewLifecycleException(
                __('domain_errors.LIVEKIT_NOT_CONFIGURED'),
                'LIVEKIT_NOT_CONFIGURED',
                503,
            );
        }

        return [$apiKey, $apiSecret];
    }

    private function laterOf(mixed $current, CarbonImmutable $candidate): CarbonImmutable
    {
        return $current !== null && CarbonImmutable::instance($current)->gt($candidate)
            ? CarbonImmutable::instance($current)
            : $candidate;
    }
}

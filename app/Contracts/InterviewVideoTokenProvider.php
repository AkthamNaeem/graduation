<?php

namespace App\Contracts;

interface InterviewVideoTokenProvider
{
    public function issueParticipantToken(
        string $apiKey,
        string $apiSecret,
        string $roomName,
        string $participantIdentity,
        string $participantName,
        int $ttlSeconds,
    ): string;
}

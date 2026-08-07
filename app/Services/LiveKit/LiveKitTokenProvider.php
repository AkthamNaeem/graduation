<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use App\Contracts\InterviewVideoTokenProvider;

class LiveKitTokenProvider implements InterviewVideoTokenProvider
{
    public function issueParticipantToken(
        string $apiKey,
        string $apiSecret,
        string $roomName,
        string $participantIdentity,
        string $participantName,
        int $ttlSeconds,
    ): string {
        $options = (new AccessTokenOptions)
            ->setIdentity($participantIdentity)
            ->setName($participantName)
            ->setTtl($ttlSeconds);

        $grant = (new VideoGrant)
            ->setRoomJoin(true)
            ->setRoomName($roomName)
            ->setCanPublish(true)
            ->setCanSubscribe(true);

        return (new AccessToken($apiKey, $apiSecret))
            ->init($options)
            ->setGrant($grant)
            ->toJwt();
    }
}

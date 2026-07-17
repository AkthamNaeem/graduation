<?php

namespace App\Services;

use InvalidArgumentException;

class EventEffectKeyFactory
{
    public function notification(
        string $event,
        string $aggregateType,
        int|string $aggregateId,
        int|string $recipientUserId,
        int|string|null $occurrenceId = null,
    ): string {
        $segments = [
            $this->segment($event),
            $this->segment($aggregateType),
            $this->segment($aggregateId),
        ];

        if ($occurrenceId !== null) {
            array_push($segments, 'occurrence', $this->segment($occurrenceId));
        }

        array_push($segments, 'notification', 'user', $this->segment($recipientUserId), 'v1');

        $key = implode(':', $segments);

        if (strlen($key) > 191) {
            throw new InvalidArgumentException('The event side-effect key exceeds 191 characters.');
        }

        return $key;
    }

    private function segment(int|string $value): string
    {
        $segment = strtolower(trim((string) $value));

        if ($segment === '' || preg_match('/\s/', $segment) === 1 || str_contains($segment, ':')) {
            throw new InvalidArgumentException('Event side-effect key segments must be non-empty and contain no spaces or colons.');
        }

        return $segment;
    }
}

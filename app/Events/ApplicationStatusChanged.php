<?php

namespace App\Events;

class ApplicationStatusChanged
{
    public function __construct(
        public readonly int $jobApplicationId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly int $changedByUserId,
        public readonly ?string $note = null,
    ) {
    }
}

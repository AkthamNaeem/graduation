<?php

namespace App\Events;

class ApplicationInformationRequestUpdated
{
    public function __construct(
        public readonly int $requestId,
        public readonly int|string|null $occurrenceId = null,
    ) {}
}

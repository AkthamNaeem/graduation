<?php

namespace App\Events;

class InterviewCancelled
{
    public function __construct(
        public readonly int $jobApplicationId,
        public readonly int $interviewId,
        public readonly ?string $scheduledAt = null,
    ) {}
}

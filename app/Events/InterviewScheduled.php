<?php

namespace App\Events;

class InterviewScheduled
{
    public function __construct(
        public readonly int $interviewId,
        public readonly ?int $historyId = null,
    ) {}
}

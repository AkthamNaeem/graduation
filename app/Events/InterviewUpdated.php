<?php

namespace App\Events;

class InterviewUpdated
{
    public function __construct(
        public readonly int $interviewId,
    ) {}
}

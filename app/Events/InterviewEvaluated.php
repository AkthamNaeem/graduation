<?php

namespace App\Events;

class InterviewEvaluated
{
    public function __construct(
        public readonly int $interviewId,
        public readonly ?int $historyId = null,
    ) {}
}

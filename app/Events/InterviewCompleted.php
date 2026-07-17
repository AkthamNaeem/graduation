<?php

namespace App\Events;

class InterviewCompleted
{
    public function __construct(public readonly int $interviewId, public readonly int $historyId) {}
}

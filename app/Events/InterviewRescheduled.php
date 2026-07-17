<?php

namespace App\Events;

class InterviewRescheduled
{
    public function __construct(public readonly int $interviewId, public readonly int $scheduleChangeId) {}
}

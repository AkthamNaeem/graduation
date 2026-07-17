<?php

namespace App\Events;

class InterviewAttendanceUpdated
{
    public function __construct(public readonly int $interviewId, public readonly int|string $occurrenceId) {}
}

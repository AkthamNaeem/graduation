<?php

namespace App\Events;

class InterviewConfirmed
{
    public function __construct(public readonly int $interviewId, public readonly int $historyId) {}
}

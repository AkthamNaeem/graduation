<?php

namespace App\Events;

class InterviewNoShow
{
    public function __construct(public readonly int $interviewId, public readonly int $historyId) {}
}

<?php

namespace App\Events;

class TestSubmitted
{
    public function __construct(
        public readonly int $testAttemptId,
    ) {}
}

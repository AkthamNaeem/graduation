<?php

namespace App\Events;

class TestEvaluated
{
    public function __construct(
        public readonly int $testAttemptId,
    ) {
    }
}

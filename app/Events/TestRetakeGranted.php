<?php

namespace App\Events;

class TestRetakeGranted
{
    public function __construct(
        public readonly int $assignmentId,
    ) {}
}

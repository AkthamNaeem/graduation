<?php

namespace App\Events;

class TestAssigned
{
    public function __construct(
        public readonly int $assignmentId,
    ) {
    }
}

<?php

namespace App\Events;

class TestAssignmentDeadlineExtended
{
    public function __construct(
        public readonly int $assignmentId,
    ) {}
}

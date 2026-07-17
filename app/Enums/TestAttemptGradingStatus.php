<?php

namespace App\Enums;

enum TestAttemptGradingStatus: string
{
    case PENDING = 'pending';
    case AUTO_GRADED = 'auto_graded';
    case MANUAL_GRADING_REQUIRED = 'manual_grading_required';
    case FULLY_GRADED = 'fully_graded';
}

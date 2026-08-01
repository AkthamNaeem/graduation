<?php

namespace App\Enums;

enum ActivityType: string
{
    case TEST = 'test';
    case INTERVIEW = 'interview';
    case INFORMATION_REQUEST = 'information_request';
    case APPLICATION_STATUS = 'application_status';
    case APPLICATION_REMINDER = 'application_reminder';
    case FINAL_DECISION = 'final_decision';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

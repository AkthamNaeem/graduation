<?php

namespace App\Enums;

enum InterviewStatus: string
{
    case SCHEDULED = 'scheduled';
    case CONFIRMED = 'confirmed';
    case RESCHEDULED = 'rescheduled';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';
    case EVALUATED = 'evaluated';

    public function isActive(): bool
    {
        return in_array($this, [self::SCHEDULED, self::CONFIRMED, self::RESCHEDULED], true);
    }
}

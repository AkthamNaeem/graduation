<?php

namespace App\Enums;

enum JobSeekerAvailabilityStatus: string
{
    case AVAILABLE_NOW = 'available_now';
    case AVAILABLE_FROM_DATE = 'available_from_date';
    case NOT_AVAILABLE = 'not_available';
}

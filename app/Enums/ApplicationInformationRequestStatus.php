<?php

namespace App\Enums;

enum ApplicationInformationRequestStatus: string
{
    case PENDING = 'pending';
    case RESPONDED = 'responded';
    case CANCELLED = 'cancelled';
}

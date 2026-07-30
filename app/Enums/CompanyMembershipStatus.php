<?php

namespace App\Enums;

enum CompanyMembershipStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case REMOVED = 'removed';
}

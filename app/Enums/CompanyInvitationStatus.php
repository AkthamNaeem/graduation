<?php

namespace App\Enums;

enum CompanyInvitationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case REVOKED = 'revoked';
    case EXPIRED = 'expired';
}

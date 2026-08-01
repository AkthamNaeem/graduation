<?php

namespace App\Enums;

enum CandidateCVOperation: string
{
    case INITIAL_UPLOAD = 'initial_upload';
    case UPDATE = 'update';
}

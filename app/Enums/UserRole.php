<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case JOB_SEEKER = 'job_seeker';
    case EMPLOYER = 'employer';
}

<?php

namespace App\Enums;

enum InterviewAttendanceStatus: string
{
    case PENDING = 'pending';
    case PRESENT = 'present';
    case ABSENT = 'absent';
    case EXCUSED = 'excused';
}

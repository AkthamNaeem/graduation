<?php

namespace App\Enums;

enum ProfileAttentionSeverity: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case ERROR = 'error';
}

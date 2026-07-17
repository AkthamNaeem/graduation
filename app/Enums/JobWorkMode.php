<?php

namespace App\Enums;

enum JobWorkMode: string
{
    case ON_SITE = 'on_site';
    case REMOTE = 'remote';
    case HYBRID = 'hybrid';

    public function requiresLocation(): bool
    {
        return $this !== self::REMOTE;
    }
}

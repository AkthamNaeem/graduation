<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Carbon;

final class DemoSeederContext
{
    private static ?Carbon $now = null;

    public static function initialize(?Carbon $now = null): void
    {
        self::$now = ($now ?? now())->startOfSecond();
    }

    public static function now(): Carbon
    {
        if (! self::$now instanceof Carbon) {
            self::initialize();
        }

        return self::$now->copy();
    }
}

<?php

namespace App\Support;

use BackedEnum;
use Illuminate\Support\Str;

class EnumLabel
{
    public static function for(BackedEnum|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = $value instanceof BackedEnum ? (string) $value->value : $value;
        $key = 'enums.values.'.$raw;
        $translated = __($key);

        return $translated === $key ? Str::headline($raw) : $translated;
    }
}

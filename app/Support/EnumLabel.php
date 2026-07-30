<?php

namespace App\Support;

use BackedEnum;

final class EnumLabel
{
    public static function for(BackedEnum|string|null $value, string $group): ?string
    {
        return LocalizedValue::make($value, $group)['value'] ?? null;
    }
}

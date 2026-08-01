<?php

namespace App\Support;

final class NameInitials
{
    public static function from(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return '?';
        }

        $initials = array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)),
            array_slice($parts, 0, 2),
        );

        return implode(' ', array_filter($initials, static fn (string $initial): bool => $initial !== '')) ?: '?';
    }
}

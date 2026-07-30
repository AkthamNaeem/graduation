<?php

namespace App\Enums;

enum ExperienceLevel: string
{
    case ENTRY_LEVEL = 'entry_level';
    case JUNIOR = 'junior';
    case MID_LEVEL = 'mid_level';
    case SENIOR = 'senior';

    public static function normalize(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'entry_level', 'entry-level', 'entry' => self::ENTRY_LEVEL,
            'junior' => self::JUNIOR,
            'mid_level', 'mid-level', 'mid' => self::MID_LEVEL,
            'senior' => self::SENIOR,
            default => null,
        };
    }

    /** @return list<string> */
    public function databaseValues(): array
    {
        return match ($this) {
            self::ENTRY_LEVEL => ['entry_level', 'entry-level', 'entry'],
            self::JUNIOR => ['junior'],
            self::MID_LEVEL => ['mid_level', 'mid-level', 'mid'],
            self::SENIOR => ['senior'],
        };
    }
}

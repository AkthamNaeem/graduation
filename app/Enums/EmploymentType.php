<?php

namespace App\Enums;

enum EmploymentType: string
{
    case FULL_TIME = 'full_time';
    case PART_TIME = 'part_time';
    case CONTRACT = 'contract';
    case INTERNSHIP = 'internship';

    public static function normalize(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'full_time', 'full-time' => self::FULL_TIME,
            'part_time', 'part-time' => self::PART_TIME,
            'contract' => self::CONTRACT,
            'internship' => self::INTERNSHIP,
            default => null,
        };
    }

    /** @return list<string> */
    public function databaseValues(): array
    {
        return match ($this) {
            self::FULL_TIME => ['full_time', 'full-time'],
            self::PART_TIME => ['part_time', 'part-time'],
            self::CONTRACT => ['contract'],
            self::INTERNSHIP => ['internship'],
        };
    }
}

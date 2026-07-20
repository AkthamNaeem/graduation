<?php

namespace App\Enums;

enum EducationLevel: string
{
    case HIGH_SCHOOL = 'high_school';
    case DIPLOMA = 'diploma';
    case BACHELOR = 'bachelor';
    case MASTER = 'master';
    case DOCTORATE = 'doctorate';

    public function rank(): int
    {
        return match ($this) {
            self::HIGH_SCHOOL => 1,
            self::DIPLOMA => 2,
            self::BACHELOR => 3,
            self::MASTER => 4,
            self::DOCTORATE => 5,
        };
    }
}

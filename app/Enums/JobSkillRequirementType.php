<?php

namespace App\Enums;

enum JobSkillRequirementType: string
{
    case REQUIRED = 'required';
    case NICE_TO_HAVE = 'nice_to_have';

    /** @deprecated Accepted only as a legacy API/database alias. */
    case OPTIONAL = 'optional';

    public function canonicalValue(): string
    {
        return $this === self::OPTIONAL ? self::NICE_TO_HAVE->value : $this->value;
    }

    public function isRequired(): bool
    {
        return $this === self::REQUIRED;
    }

    public function isNiceToHave(): bool
    {
        return $this === self::NICE_TO_HAVE || $this === self::OPTIONAL;
    }

    public static function normalize(string $value): ?self
    {
        return match ($value) {
            self::REQUIRED->value => self::REQUIRED,
            self::NICE_TO_HAVE->value, self::OPTIONAL->value => self::NICE_TO_HAVE,
            default => null,
        };
    }
}

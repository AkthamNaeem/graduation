<?php

namespace App\Enums;

enum ProfileAction: string
{
    case EDIT_PROFILE = 'edit_profile';
    case MANAGE_EXPERIENCES = 'manage_experiences';
    case MANAGE_EDUCATION = 'manage_education';
    case MANAGE_SKILLS = 'manage_skills';
    case MANAGE_LINKS = 'manage_links';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

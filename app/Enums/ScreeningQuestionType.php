<?php

namespace App\Enums;

enum ScreeningQuestionType: string
{
    case SHORT_TEXT = 'short_text';
    case LONG_TEXT = 'long_text';
    case SINGLE_CHOICE = 'single_choice';
    case MULTIPLE_CHOICE = 'multiple_choice';
    case BOOLEAN = 'boolean';
    case NUMBER = 'number';

    public function isChoice(): bool
    {
        return $this === self::SINGLE_CHOICE || $this === self::MULTIPLE_CHOICE;
    }
}

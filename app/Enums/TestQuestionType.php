<?php

namespace App\Enums;

enum TestQuestionType: string
{
    case SINGLE_CHOICE = 'single_choice';
    case MULTIPLE_CHOICE = 'multiple_choice';
    case TRUE_FALSE = 'true_false';
    case SHORT_TEXT = 'short_text';
    case LONG_TEXT = 'long_text';
    case FILE_UPLOAD = 'file_upload';

    public function acceptsOptions(): bool
    {
        return in_array($this, [
            self::SINGLE_CHOICE,
            self::MULTIPLE_CHOICE,
            self::TRUE_FALSE,
        ], true);
    }

    public function requiresManualGrading(): bool
    {
        return ! $this->acceptsOptions();
    }
}

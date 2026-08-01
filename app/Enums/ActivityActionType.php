<?php

namespace App\Enums;

enum ActivityActionType: string
{
    case START_TEST = 'start_test';
    case CONTINUE_TEST = 'continue_test';
    case SUBMIT_INFORMATION = 'submit_information';
    case CONFIRM_INTERVIEW = 'confirm_interview';
    case VIEW_INTERVIEW = 'view_interview';
    case VIEW_APPLICATION = 'view_application';
    case VIEW_TEST_RESULT = 'view_test_result';
    case NONE = 'none';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

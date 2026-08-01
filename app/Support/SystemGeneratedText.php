<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

final class SystemGeneratedText
{
    private const PREFIX = 'system:';

    /** @var array<string, string> */
    private const LEGACY_KEYS = [
        'Application interview status recalculated.' => 'application_interview_recalculated',
        'Interview closed as no show.' => 'interview_closed_no_show',
        'Interview completed for candidate.' => 'interview_completed_candidate',
        'Interview scheduled for candidate.' => 'interview_scheduled_candidate',
        'Final interview evaluation completed.' => 'interview_final_evaluation',
        'Cancelled through the legacy delete endpoint.' => 'interview_legacy_cancelled',
        'Test assigned to candidate.' => 'test_assigned_candidate',
        'Test attempt submitted.' => 'test_attempt_submitted',
        'Test attempt evaluated.' => 'test_attempt_evaluated',
        'Matched by title, company, and start period.' => 'profile_experience_matched',
        'New experience found in parsed CV.' => 'profile_experience_new',
        'Matched by institution, degree, and start or graduation period.' => 'profile_education_matched',
        'New education entry found in parsed CV.' => 'profile_education_new',
        'Skill already exists on the profile.' => 'profile_skill_matched',
        'New skill found in parsed CV.' => 'profile_skill_new',
        'The CV contains a profile value for review.' => 'profile_value_review',
        'The profile value already matches.' => 'profile_value_matched',
        'The profile city already matches the parsed CV.' => 'profile_city_matched',
        'A Syrian city was identified from the parsed CV location for review.' => 'profile_city_review',
        'The selected option exactly matches the correct option.' => 'grading_single_correct',
        'The selected option does not match the correct option.' => 'grading_single_incorrect',
        'The selected option set exactly matches the correct option set.' => 'grading_multiple_correct',
        'The selected option set does not exactly match the correct option set.' => 'grading_multiple_incorrect',
        'Model attribution only; not a probability or hiring decision.' => 'ml_attribution',
    ];

    public static function token(string $key): string
    {
        return self::PREFIX.$key;
    }

    public static function resolve(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $key = str_starts_with($value, self::PREFIX)
            ? substr($value, strlen(self::PREFIX))
            : (self::LEGACY_KEYS[$value] ?? null);

        if ($key === null || ! Lang::has('system.'.$key)) {
            return $value;
        }

        return __('system.'.$key);
    }
}

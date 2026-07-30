<?php

namespace Tests\Unit;

use App\Enums\ApplicationInformationRequestStatus;
use App\Enums\CompanyApprovalStatus;
use App\Enums\CompanyInvitationStatus;
use App\Enums\CompanyMembershipStatus;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Enums\ExperienceLevel;
use App\Enums\InterviewAttendanceStatus;
use App\Enums\InterviewMode;
use App\Enums\InterviewStatus;
use App\Enums\InterviewType;
use App\Enums\JobSkillRequirementType;
use App\Enums\JobWorkMode;
use App\Enums\ScreeningQuestionType;
use App\Enums\TestAnswerGradingType;
use App\Enums\TestAttemptGradingStatus;
use App\Enums\TestQuestionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\LocalizedValue;
use BackedEnum;
use Illuminate\Support\Arr;
use LogicException;
use Tests\TestCase;

class LocalizedValueTest extends TestCase
{
    public function test_every_backed_enum_case_has_a_strict_bilingual_key_value_contract(): void
    {
        /** @var array<class-string<BackedEnum>, string> $groups */
        $groups = [
            ApplicationInformationRequestStatus::class => 'application_information_request_statuses',
            CompanyApprovalStatus::class => 'company_approval_statuses',
            CompanyInvitationStatus::class => 'company_invitation_statuses',
            CompanyMembershipStatus::class => 'company_membership_statuses',
            CompanyPermission::class => 'company_permissions',
            CompanyRole::class => 'company_roles',
            EducationLevel::class => 'education_levels',
            EmploymentType::class => 'employment_types',
            ExperienceLevel::class => 'experience_levels',
            InterviewAttendanceStatus::class => 'interview_attendance_statuses',
            InterviewMode::class => 'interview_modes',
            InterviewStatus::class => 'interview_statuses',
            InterviewType::class => 'interview_types',
            JobSkillRequirementType::class => 'job_skill_requirement_types',
            JobWorkMode::class => 'job_work_modes',
            ScreeningQuestionType::class => 'screening_question_types',
            TestAnswerGradingType::class => 'test_grading_types',
            TestAttemptGradingStatus::class => 'test_grading_statuses',
            TestQuestionType::class => 'test_question_types',
            UserRole::class => 'user_roles',
            UserStatus::class => 'user_statuses',
        ];

        foreach ($groups as $enum => $group) {
            foreach ($enum::cases() as $case) {
                app()->setLocale('en');
                $english = LocalizedValue::make($case, $group);
                app()->setLocale('ar');
                $arabic = LocalizedValue::make($case, $group);

                $this->assertSame($case->value, $english['key']);
                $this->assertSame($english['key'], $arabic['key']);
                $this->assertNotSame($english['value'], $arabic['value']);
            }
        }
    }

    public function test_every_option_catalog_leaf_resolves_without_fallback_in_both_locales(): void
    {
        $english = Arr::dot(require lang_path('en/options.php'));
        $arabic = Arr::dot(require lang_path('ar/options.php'));

        $this->assertSame(array_keys($english), array_keys($arabic));

        foreach ($english as $path => $englishValue) {
            [$group, $key] = explode('.', $path, 2);
            $this->assertNotSame('', trim((string) $englishValue), "{$path} has an empty English value.");
            $this->assertNotSame('', trim((string) $arabic[$path]), "{$path} has an empty Arabic value.");

            app()->setLocale('en');
            $englishContract = LocalizedValue::make($key, $group);
            app()->setLocale('ar');
            $arabicContract = LocalizedValue::make($key, $group);

            $this->assertSame($key, $englishContract['key']);
            $this->assertSame($key, $arabicContract['key']);
            $this->assertSame($englishValue, $englishContract['value']);
            $this->assertSame($arabic[$path], $arabicContract['value']);
        }
    }

    public function test_missing_option_never_falls_back_to_a_headline_or_another_locale(): void
    {
        app()->setLocale('ar');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Missing localized option: options.job_statuses.not_a_real_status [ar]',
        );

        LocalizedValue::make('not_a_real_status', 'job_statuses');
    }

    public function test_job_category_aliases_normalize_to_canonical_machine_values(): void
    {
        $this->assertSame('full_time', EmploymentType::normalize('full-time')?->value);
        $this->assertSame('part_time', EmploymentType::normalize('part-time')?->value);
        $this->assertSame('entry_level', ExperienceLevel::normalize('entry')?->value);
        $this->assertSame('entry_level', ExperienceLevel::normalize('entry-level')?->value);
        $this->assertSame('mid_level', ExperienceLevel::normalize('mid')?->value);
        $this->assertSame('mid_level', ExperienceLevel::normalize('mid-level')?->value);
    }
}

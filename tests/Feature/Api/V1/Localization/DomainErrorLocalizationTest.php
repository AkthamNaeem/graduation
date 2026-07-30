<?php

namespace Tests\Feature\Api\V1\Localization;

use App\Support\ApiResponse;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class DomainErrorLocalizationTest extends TestCase
{
    #[DataProvider('domainErrorProvider')]
    public function test_known_domain_error_has_exact_bilingual_message(
        string $code,
        int $status,
        string $english,
        string $arabic,
    ): void {
        foreach (['en' => $english, 'ar' => $arabic] as $locale => $expected) {
            app()->setLocale($locale);

            $response = ApiResponse::error(
                message: 'Legacy message must not control the localized response.',
                status: $status,
                code: $code,
            );
            $payload = $response->getData(true);

            $this->assertSame($status, $response->getStatusCode());
            $this->assertSame($code, $payload['code']);
            $this->assertSame($expected, $payload['message']);
            $this->assertNotSame(__('api.domain_error', locale: $locale), $payload['message']);
        }
    }

    public function test_every_known_domain_code_has_a_non_generic_translation_in_both_locales(): void
    {
        $english = Arr::dot(require lang_path('en/domain_errors.php'));
        $arabic = Arr::dot(require lang_path('ar/domain_errors.php'));

        $this->assertSame(array_keys($english), array_keys($arabic));

        foreach ($english as $code => $englishMessage) {
            $this->assertIsString($englishMessage, $code);
            $this->assertNotSame('', trim($englishMessage), $code);
            $this->assertIsString($arabic[$code], $code);
            $this->assertNotSame('', trim($arabic[$code]), $code);
            $this->assertNotSame($englishMessage, $arabic[$code], $code);
            $this->assertNotSame('The requested operation could not be completed.', $englishMessage, $code);
            $this->assertNotSame('تعذر تنفيذ العملية المطلوبة.', $arabic[$code], $code);
        }
    }

    public function test_every_domain_exception_code_used_by_the_application_is_catalogued(): void
    {
        $codes = [
            'COMPANY_PENDING',
            'COMPANY_REJECTED',
            'COMPANY_SUSPENDED',
            'EMAIL_VERIFICATION_NOT_FOUND',
            'INVALID_OR_EXPIRED_PASSWORD_RESET_OTP',
            'INVALID_OTP',
            'OTP_ATTEMPTS_EXCEEDED',
            'OTP_EXPIRED',
            'OTP_RESEND_COOLDOWN',
            'PASSWORD_RESET_OTP_ATTEMPTS_EXCEEDED',
            'USER_SUSPENDED',
        ];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        $renderedException = implode('|', [
            'ApplicationInformationRequestException',
            'ApplicationInternalNoteException',
            'CompanyManagementException',
            'CVLifecycleException',
            'EmailVerificationException',
            'InterviewLifecycleException',
            'JobPostingOperationException',
            'PasswordResetOtpException',
            'PrivateFileStorageException',
            'RecruitmentAccessException',
            'TestAttemptTimingException',
            'TestContentAccessException',
            'TestScorePolicyException',
        ]);

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            preg_match_all(
                '/(?:new\s+(?:'.$renderedException.')|(?:\$this->)?fail)\s*\(\s*(?:__\([^)]*\)|[\'"][^\'"]*[\'"])\s*,\s*[\'"]([A-Z][A-Z0-9_]+)[\'"]/s',
                (string) $source,
                $matches,
            );
            $codes = [...$codes, ...$matches[1]];
        }

        $english = require lang_path('en/domain_errors.php');
        $arabic = require lang_path('ar/domain_errors.php');

        foreach (array_values(array_unique($codes)) as $code) {
            $this->assertArrayHasKey($code, $english, $code);
            $this->assertArrayHasKey($code, $arabic, $code);
        }
    }

    /**
     * @return array<string, array{string, int, string, string}>
     */
    public static function domainErrorProvider(): array
    {
        return [
            'application duplicate' => ['APPLICATION_ALREADY_EXISTS', 422, 'You have already applied for this job.', 'لقد تقدمت إلى هذه الوظيفة مسبقًا.'],
            'invalid application transition' => ['APPLICATION_INVALID_STATUS_TRANSITION', 422, 'The requested application status transition is not allowed.', 'الانتقال المطلوب بين حالات طلب التوظيف غير مسموح.'],
            'job closed' => ['JOB_NOT_ACCEPTING_APPLICATIONS', 422, 'This job is not accepting applications.', 'هذه الوظيفة لا تستقبل طلبات توظيف.'],
            'job deadline passed' => ['JOB_APPLICATION_DEADLINE_PASSED', 409, 'The application deadline has passed or is not a future date.', 'انتهى موعد التقديم أو أنه ليس تاريخًا مستقبليًا.'],
            'test expired' => ['TEST_ATTEMPT_TIME_EXPIRED', 409, 'The allowed time for this test attempt has expired.', 'انتهى الوقت المسموح لمحاولة الاختبار.'],
            'test already submitted' => ['TEST_ATTEMPT_ALREADY_SUBMITTED', 409, 'This test attempt has already been submitted and can no longer be modified.', 'تم إرسال محاولة الاختبار هذه ولا يمكن تعديلها بعد الآن.'],
            'interview conflict' => ['INTERVIEW_ALREADY_ACTIVE_FOR_TYPE', 409, 'An active interview of this type already exists for the application.', 'توجد مقابلة نشطة من هذا النوع لطلب التوظيف.'],
            'interview cancelled action' => ['INTERVIEW_CANCELLATION_NOT_ALLOWED', 409, 'Only an active interview can be cancelled.', 'يمكن إلغاء المقابلات النشطة فقط.'],
            'interview completed action' => ['INTERVIEW_COMPLETION_NOT_ALLOWED', 409, 'This interview cannot be completed in its current state.', 'لا يمكن إكمال المقابلة في حالتها الحالية.'],
            'company not approved' => ['COMPANY_RECRUITMENT_UNAVAILABLE', 403, 'Recruitment activity for this company is currently unavailable.', 'نشاط التوظيف لهذه الشركة غير متاح حاليًا.'],
            'invitation expired' => ['COMPANY_INVITATION_EXPIRED', 410, 'This company invitation has expired.', 'انتهت صلاحية دعوة الشركة هذه.'],
            'cv review state' => ['CV_REVIEW_DRAFT_NOT_EDITABLE', 409, 'This CV review draft cannot be edited.', 'لا يمكن تعديل مسودة مراجعة السيرة الذاتية هذه.'],
            'resource ownership' => ['CV_NOT_OWNED', 403, 'The selected CV does not belong to the authenticated job seeker.', 'السيرة الذاتية المحددة لا تخص الباحث عن عمل المصادق عليه.'],
            'company ownership' => ['COMPANY_OWNERSHIP_TARGET_INVALID', 422, 'The ownership target must be an active member of this company.', 'يجب أن يكون المالك الجديد عضوًا نشطًا في هذه الشركة.'],
        ];
    }
}

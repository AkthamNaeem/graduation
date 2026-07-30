<?php

namespace App\Services\Auth;

use App\Exceptions\EmailVerificationException;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmailVerificationService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly OtpCodeService $otpCodeService,
    ) {}

    public function issueOtp(User $user): EmailVerificationOtp
    {
        if ($user->email_verified_at !== null) {
            throw new EmailVerificationException(
                'The email address is already verified.',
                'EMAIL_ALREADY_VERIFIED',
                409,
            );
        }

        $this->ensureDriverAvailable();

        $now = now();
        $otp = EmailVerificationOtp::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($this->otpCodeService->generate()),
                'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
                'attempts' => 0,
                'last_issued_at' => $now,
            ],
        );

        $this->auditLogService->record(
            'email_verification_otp_issued',
            $user,
            User::class,
            $user->id,
            metadata: ['driver' => $this->otpCodeService->driver()],
        );

        return $otp;
    }

    /**
     * @return array{token: string, user: User}
     */
    public function verifyOtp(string $email, string $submittedOtp): array
    {
        $this->ensureDriverAvailable();

        $result = DB::transaction(function () use ($email, $submittedOtp): array {
            $user = User::query()
                ->where('email', $this->normalizeEmail($email))
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return ['error' => $this->error(
                    'No pending email verification was found.',
                    'EMAIL_VERIFICATION_NOT_FOUND',
                    422,
                )];
            }

            if ($user->email_verified_at !== null) {
                return ['error' => $this->error(
                    'The email address is already verified.',
                    'EMAIL_ALREADY_VERIFIED',
                    409,
                )];
            }

            $otp = EmailVerificationOtp::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return ['error' => $this->error(
                    'No pending email verification was found.',
                    'EMAIL_VERIFICATION_NOT_FOUND',
                    422,
                )];
            }

            if ($otp->expires_at->isPast()) {
                return ['error' => $this->error(
                    'The verification code has expired.',
                    'OTP_EXPIRED',
                    422,
                )];
            }

            if ($otp->attempts >= $this->maxAttempts()) {
                return ['error' => $this->error(
                    'The maximum number of verification attempts has been reached.',
                    'OTP_ATTEMPTS_EXCEEDED',
                    429,
                )];
            }

            if (! Hash::check($submittedOtp, $otp->code_hash)) {
                $otp->forceFill(['attempts' => $otp->attempts + 1])->save();

                return ['error' => $this->error(
                    'The verification code is invalid.',
                    'INVALID_OTP',
                    422,
                )];
            }

            $user->forceFill(['email_verified_at' => now()])->save();
            $otp->delete();

            $this->auditLogService->record(
                'email_verified',
                $user,
                User::class,
                $user->id,
                before: ['is_email_verified' => false],
                after: ['is_email_verified' => true],
                metadata: ['driver' => $this->otpCodeService->driver()],
            );

            return ['user' => $user];
        });

        if (isset($result['error'])) {
            $this->throwError($result['error']);
        }

        /** @var User $user */
        $user = $result['user'];
        $user->loadMissing([
            'jobSeekerProfile.experiences',
            'jobSeekerProfile.education',
            'jobSeekerProfile.skills',
            'employerProfile.company',
        ]);

        return [
            'token' => $user->createToken('api-token')->plainTextToken,
            'user' => $user,
        ];
    }

    public function reissueOtpForEmail(string $email): void
    {
        $result = DB::transaction(function () use ($email): array {
            $user = User::query()
                ->where('email', $this->normalizeEmail($email))
                ->lockForUpdate()
                ->first();

            if (! $user || $user->email_verified_at !== null) {
                return ['generic' => true];
            }

            $this->ensureDriverAvailable();

            $otp = EmailVerificationOtp::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($otp) {
                $availableAt = $otp->last_issued_at
                    ->copy()
                    ->addSeconds($this->resendCooldownSeconds());

                if ($availableAt->isFuture()) {
                    return ['error' => $this->error(
                        'Please wait before requesting another verification code.',
                        'OTP_RESEND_COOLDOWN',
                        429,
                        responseData: [
                            'retry_after_seconds' => max(
                                1,
                                (int) now()->diffInSeconds($availableAt),
                            ),
                        ],
                    )];
                }
            }

            $this->issueOtp($user);

            return ['generic' => true];
        });

        if (isset($result['error'])) {
            $this->throwError($result['error']);
        }
    }

    /**
     * @return array{
     *     required: true,
     *     delivery_channel: string,
     *     sent: false,
     *     expires_in_seconds: int,
     *     resend_after_seconds: int
     * }
     */
    public function getVerificationMetadata(): array
    {
        return [
            'required' => true,
            'delivery_channel' => $this->otpCodeService->driver(),
            'sent' => false,
            'expires_in_seconds' => $this->ttlMinutes() * 60,
            'resend_after_seconds' => $this->resendCooldownSeconds(),
        ];
    }

    public function ensureDriverAvailable(): void
    {
        $this->otpCodeService->ensureAvailable();
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('otp.ttl_minutes', 5));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('otp.max_attempts', 5));
    }

    private function resendCooldownSeconds(): int
    {
        return max(0, (int) config('otp.resend_cooldown_seconds', 60));
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array{message: string, code: string, status: int, errors: array<string, mixed>, response_data: array<string, mixed>}
     */
    private function error(
        string $message,
        string $code,
        int $status,
        array $errors = [],
        array $responseData = [],
    ): array {
        return compact('message', 'code', 'status', 'errors') + [
            'response_data' => $responseData,
        ];
    }

    /**
     * @param  array{message: string, code: string, status: int, errors: array<string, mixed>, response_data: array<string, mixed>}  $error
     */
    private function throwError(array $error): never
    {
        throw new EmailVerificationException(
            $error['message'],
            $error['code'],
            $error['status'],
            $error['errors'],
            $error['response_data'],
        );
    }
}

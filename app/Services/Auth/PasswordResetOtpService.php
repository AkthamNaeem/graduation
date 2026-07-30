<?php

namespace App\Services\Auth;

use App\Exceptions\PasswordResetOtpException;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordResetOtpService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly OtpCodeService $otpCodeService,
    ) {}

    public function issueForEmail(string $email): void
    {
        DB::transaction(function () use ($email): void {
            $user = User::query()
                ->where('email', $this->normalizeEmail($email))
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return;
            }

            $this->otpCodeService->ensureAvailable();

            $otp = PasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($otp) {
                $availableAt = $otp->last_issued_at
                    ->copy()
                    ->addSeconds($this->resendCooldownSeconds());

                if ($availableAt->isFuture()) {
                    return;
                }
            }

            $now = now();
            PasswordResetOtp::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'code_hash' => Hash::make($this->otpCodeService->generate()),
                    'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
                    'attempts' => 0,
                    'last_issued_at' => $now,
                ],
            );

            $this->auditLogService->record(
                'password_reset_otp_issued',
                $user,
                User::class,
                $user->id,
                metadata: ['driver' => $this->otpCodeService->driver()],
            );
        });
    }

    public function resetPassword(string $email, string $submittedOtp, string $password): void
    {
        $this->otpCodeService->ensureAvailable();

        $result = DB::transaction(function () use ($email, $submittedOtp, $password): array {
            $user = User::query()
                ->where('email', $this->normalizeEmail($email))
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return ['error' => $this->invalidOtpError()];
            }

            $otp = PasswordResetOtp::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->expires_at->isPast()) {
                return ['error' => $this->invalidOtpError()];
            }

            if ($otp->attempts >= $this->maxAttempts()) {
                return ['error' => [
                    'message' => 'The maximum number of password reset attempts has been reached.',
                    'code' => 'PASSWORD_RESET_OTP_ATTEMPTS_EXCEEDED',
                    'status' => 429,
                    'errors' => [
                        'otp' => ['The maximum number of password reset attempts has been reached.'],
                    ],
                ]];
            }

            if (! Hash::check($submittedOtp, $otp->code_hash)) {
                $otp->forceFill(['attempts' => $otp->attempts + 1])->save();

                return ['error' => $this->invalidOtpError()];
            }

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            $otp->delete();

            event(new PasswordReset($user));

            $this->auditLogService->record(
                'password_reset_completed',
                $user,
                User::class,
                $user->id,
                metadata: ['driver' => $this->otpCodeService->driver()],
            );

            return ['success' => true];
        });

        if (isset($result['error'])) {
            $error = $result['error'];

            throw new PasswordResetOtpException(
                $error['message'],
                $error['code'],
                $error['status'],
                $error['errors'],
            );
        }
    }

    /**
     * @return array{
     *     delivery_channel: string,
     *     sent: false,
     *     expires_in_seconds: int,
     *     retry_after_seconds: int
     * }
     */
    public function getMetadata(): array
    {
        return [
            'delivery_channel' => $this->otpCodeService->driver(),
            'sent' => false,
            'expires_in_seconds' => $this->ttlMinutes() * 60,
            'retry_after_seconds' => $this->resendCooldownSeconds(),
        ];
    }

    /**
     * @return array{message: string, code: string, status: int, errors: array<string, list<string>>}
     */
    private function invalidOtpError(): array
    {
        return [
            'message' => 'The password reset code is invalid or expired.',
            'code' => 'INVALID_OR_EXPIRED_PASSWORD_RESET_OTP',
            'status' => 422,
            'errors' => [
                'otp' => ['The password reset code is invalid or expired.'],
            ],
        ];
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
}

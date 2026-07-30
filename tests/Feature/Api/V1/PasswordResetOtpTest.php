<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EmailVerificationOtp;
use App\Models\EmployerProfile;
use App\Models\JobSeekerProfile;
use App\Models\PasswordResetOtp;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_issues_hashed_otp_without_delivery_or_enumeration(): void
    {
        Notification::fake();
        Http::preventStrayRequests();

        $user = User::factory()->create(['email' => 'forgot@example.com']);
        $existing = $this->forgot(' FORGOT@EXAMPLE.COM ');

        $existing->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'If an account with that email exists, a password reset code is available.')
            ->assertJsonPath('data.delivery_channel', 'static')
            ->assertJsonPath('data.sent', false)
            ->assertJsonPath('data.expires_in_seconds', 300)
            ->assertJsonPath('data.retry_after_seconds', 60);

        $otp = PasswordResetOtp::query()->whereBelongsTo($user)->firstOrFail();

        $this->assertNotSame($this->staticOtp(), $otp->code_hash);
        $this->assertTrue(Hash::check($this->staticOtp(), $otp->code_hash));
        $this->assertSame(0, $otp->attempts);
        $this->assertPayloadDoesNotExposeSecrets($existing->json());

        $unknown = $this->forgot('unknown-forgot@example.com')->assertOk();

        $this->assertSame($existing->json('message'), $unknown->json('message'));
        $this->assertSame($existing->json('data'), $unknown->json('data'));
        $this->assertDatabaseCount('password_reset_otps', 1);
        $this->assertDatabaseMissing('users', ['email' => 'unknown-forgot@example.com']);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_cooldown_is_generic_and_reissue_after_cooldown_refreshes_record(): void
    {
        Carbon::setTestNow('2026-07-30 10:00:00');
        $user = User::factory()->create(['email' => 'cooldown-reset@example.com']);
        $first = $this->forgot($user->email)->assertOk();
        $otp = PasswordResetOtp::query()->whereBelongsTo($user)->firstOrFail();
        $otp->forceFill(['attempts' => 4])->save();

        $originalHash = $otp->code_hash;
        $originalExpiry = $otp->expires_at->copy();
        $originalIssuedAt = $otp->last_issued_at->copy();

        $duringCooldown = $this->forgot($user->email)->assertOk();
        $otp->refresh();

        $this->assertSame($first->json(), $duringCooldown->json());
        $this->assertSame($originalHash, $otp->code_hash);
        $this->assertTrue($originalExpiry->equalTo($otp->expires_at));
        $this->assertTrue($originalIssuedAt->equalTo($otp->last_issued_at));
        $this->assertSame(4, $otp->attempts);
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'password_reset_otp_issued')->count(),
        );

        Carbon::setTestNow(now()->addSeconds(61));
        $this->forgot($user->email)->assertOk();
        $otp->refresh();

        $this->assertNotSame($originalHash, $otp->code_hash);
        $this->assertTrue($otp->expires_at->greaterThan($originalExpiry));
        $this->assertSame(now()->timestamp, $otp->last_issued_at->timestamp);
        $this->assertSame(now()->addMinutes(5)->timestamp, $otp->expires_at->timestamp);
        $this->assertSame(0, $otp->attempts);
        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'password_reset_otp_issued')->count(),
        );

        Carbon::setTestNow();
    }

    public function test_successful_reset_changes_password_revokes_tokens_and_consumes_otp(): void
    {
        Event::fake([PasswordReset::class]);

        $user = User::factory()->create([
            'email' => 'successful-reset@example.com',
            'remember_token' => 'old-remember-token',
            'role' => UserRole::JOB_SEEKER,
            'status' => UserStatus::ACTIVE,
        ]);
        JobSeekerProfile::query()->create([
            'user_id' => $user->id,
            'phone' => '+963 900 111 222',
        ]);
        $user->createToken('first-device');
        $user->createToken('second-device');
        $verifiedAt = $user->email_verified_at?->toISOString();

        $this->forgot($user->email)->assertOk();
        $response = $this->reset($user->email)->assertOk()
            ->assertJsonPath('message', 'Password reset successfully.')
            ->assertJsonPath('data', null);

        $user->refresh();

        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertSame($verifiedAt, $user->email_verified_at?->toISOString());
        $this->assertSame(UserRole::JOB_SEEKER, $user->role);
        $this->assertSame('+963 900 111 222', $user->jobSeekerProfile->phone);
        $this->assertDatabaseCount('password_reset_otps', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertArrayNotHasKey('token', (array) $response->json('data'));

        Event::assertDispatched(
            PasswordReset::class,
            fn (PasswordReset $event): bool => $event->user->is($user),
        );

        $audit = AuditLog::query()
            ->where('action', 'password_reset_completed')
            ->firstOrFail();
        $this->assertSame(['driver' => 'static'], $audit->metadata);
        $this->assertPayloadDoesNotExposeSecrets($audit->toArray());

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'new-password',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(401);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->reset($user->email)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_OR_EXPIRED_PASSWORD_RESET_OTP');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_incorrect_otp_increments_attempts_and_maximum_blocks_reset(): void
    {
        config()->set('otp.max_attempts', 2);
        $user = User::factory()->create(['email' => 'attempt-reset@example.com']);
        $this->forgot($user->email);
        $otp = PasswordResetOtp::query()->firstOrFail();
        $hash = $otp->code_hash;
        $expiry = $otp->expires_at->copy();

        $this->reset($user->email, $this->wrongOtp())
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_OR_EXPIRED_PASSWORD_RESET_OTP');

        $otp->refresh();
        $this->assertSame(1, $otp->attempts);
        $this->assertSame($hash, $otp->code_hash);
        $this->assertTrue($expiry->equalTo($otp->expires_at));
        $this->assertTrue(Hash::check('password', $user->refresh()->password));

        $this->reset($user->email, $this->wrongOtp())->assertStatus(422);
        $this->reset($user->email)
            ->assertStatus(429)
            ->assertJsonPath('code', 'PASSWORD_RESET_OTP_ATTEMPTS_EXCEEDED');

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertDatabaseHas('password_reset_otps', [
            'user_id' => $user->id,
            'attempts' => 2,
        ]);
    }

    public function test_expired_missing_and_unknown_reset_requests_share_generic_error(): void
    {
        $expired = User::factory()->create(['email' => 'expired-reset@example.com']);
        $missing = User::factory()->create(['email' => 'missing-reset@example.com']);
        $this->forgot($expired->email);
        PasswordResetOtp::query()
            ->where('user_id', $expired->id)
            ->update(['expires_at' => now()->subSecond()]);

        $expiredResponse = $this->reset($expired->email)->assertStatus(422);
        $missingResponse = $this->reset($missing->email)->assertStatus(422);
        $unknownResponse = $this->reset('unknown-reset@example.com')->assertStatus(422);

        foreach ([$expiredResponse, $missingResponse, $unknownResponse] as $response) {
            $response->assertJsonPath('code', 'INVALID_OR_EXPIRED_PASSWORD_RESET_OTP')
                ->assertJsonPath('message', 'The password reset code is invalid or expired.')
                ->assertJsonValidationErrors(['otp']);
        }

        $this->assertSame($expiredResponse->json(), $missingResponse->json());
        $this->assertSame($missingResponse->json(), $unknownResponse->json());
        $this->assertTrue(Hash::check('password', $expired->refresh()->password));
        $this->assertTrue(Hash::check('password', $missing->refresh()->password));
    }

    public function test_email_verification_and_password_reset_otp_records_are_independent(): void
    {
        $emailVerification = app(EmailVerificationService::class);

        $verificationOnly = User::factory()->unverified()->create([
            'email' => 'verification-only@example.com',
        ]);
        $emailVerification->issueOtp($verificationOnly);
        $this->reset($verificationOnly->email)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_OR_EXPIRED_PASSWORD_RESET_OTP');

        $resetOnly = User::factory()->unverified()->create([
            'email' => 'reset-only@example.com',
        ]);
        $this->forgot($resetOnly->email);
        $this->verifyEmail($resetOnly->email)
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_VERIFICATION_NOT_FOUND');

        $both = User::factory()->unverified()->create(['email' => 'both-otps@example.com']);
        $emailVerification->issueOtp($both);
        $verificationRecord = EmailVerificationOtp::query()
            ->whereBelongsTo($both)
            ->firstOrFail();
        $this->forgot($both->email);
        $resetRecord = PasswordResetOtp::query()->whereBelongsTo($both)->firstOrFail();

        $this->assertDatabaseHas('email_verification_otps', ['id' => $verificationRecord->id]);
        $this->assertDatabaseHas('password_reset_otps', ['id' => $resetRecord->id]);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->postJson('/api/v1/auth/email/resend-otp', ['email' => $both->email])
            ->assertOk();
        $this->assertSame(
            $resetRecord->code_hash,
            PasswordResetOtp::query()->findOrFail($resetRecord->id)->code_hash,
        );

        $this->reset($both->email)->assertOk();
        $this->assertDatabaseHas('email_verification_otps', ['id' => $verificationRecord->id]);
        $this->assertDatabaseMissing('password_reset_otps', ['id' => $resetRecord->id]);
        $this->assertNull($both->refresh()->email_verified_at);

        Carbon::setTestNow();

        $verifyLeavesReset = User::factory()->unverified()->create([
            'email' => 'verify-leaves-reset@example.com',
        ]);
        $emailVerification->issueOtp($verifyLeavesReset);
        $this->forgot($verifyLeavesReset->email);
        $passwordResetId = PasswordResetOtp::query()
            ->whereBelongsTo($verifyLeavesReset)
            ->valueOrFail('id');

        $this->verifyEmail($verifyLeavesReset->email)->assertOk();
        $this->assertDatabaseHas('password_reset_otps', ['id' => $passwordResetId]);
        $this->assertDatabaseMissing('email_verification_otps', [
            'user_id' => $verifyLeavesReset->id,
        ]);
    }

    public function test_unverified_and_suspended_users_keep_account_restrictions_after_reset(): void
    {
        $unverified = User::factory()->unverified()->create([
            'email' => 'unverified-reset@example.com',
            'status' => UserStatus::ACTIVE,
        ]);
        JobSeekerProfile::query()->create([
            'user_id' => $unverified->id,
            'phone' => '+963 900 333 444',
        ]);

        $this->forgot($unverified->email);
        $this->reset($unverified->email)->assertOk();

        $unverified->refresh();
        $this->assertNull($unverified->email_verified_at);
        $this->assertSame(UserStatus::ACTIVE, $unverified->status);
        $this->assertSame('+963 900 333 444', $unverified->jobSeekerProfile->phone);
        $this->postJson('/api/v1/auth/login', [
            'email' => $unverified->email,
            'password' => 'new-password',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        $company = Company::query()->create(['name' => 'Suspended Reset Co.']);
        $suspended = User::factory()->create([
            'email' => 'suspended-reset@example.com',
            'role' => UserRole::EMPLOYER,
            'status' => UserStatus::SUSPENDED,
        ]);
        EmployerProfile::query()->create([
            'user_id' => $suspended->id,
            'company_id' => $company->id,
            'phone' => '+963 900 555 666',
        ]);

        $this->forgot($suspended->email);
        $this->reset($suspended->email)->assertOk();

        $suspended->refresh();
        $this->assertSame(UserStatus::SUSPENDED, $suspended->status);
        $this->assertSame(UserRole::EMPLOYER, $suspended->role);
        $this->assertSame($company->id, $suspended->employerProfile->company_id);
        $this->assertSame('+963 900 555 666', $suspended->employerProfile->phone);
        $this->postJson('/api/v1/auth/login', [
            'email' => $suspended->email,
            'password' => 'new-password',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'USER_SUSPENDED');
    }

    public function test_change_password_remains_authenticated_current_password_flow_without_otp(): void
    {
        $user = User::factory()->create(['email' => 'change-separate@example.com']);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(401);

        $token = $user->createToken('change-password-token')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()
            ->assertJsonPath('message', 'Password changed successfully.');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
        $this->assertDatabaseCount('password_reset_otps', 0);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_static_password_reset_is_guarded_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $blocked = User::factory()->create(['email' => 'blocked-production-reset@example.com']);
        config()->set('otp.allow_static_in_production', false);

        $this->forgot($blocked->email)
            ->assertStatus(503)
            ->assertJsonPath('code', 'OTP_DRIVER_NOT_AVAILABLE');
        $this->assertDatabaseMissing('password_reset_otps', ['user_id' => $blocked->id]);

        config()->set('otp.allow_static_in_production', true);
        $allowed = User::factory()->create(['email' => 'allowed-production-reset@example.com']);

        $this->forgot($allowed->email)->assertOk();
        $this->reset($allowed->email)->assertOk();
        $this->assertTrue(Hash::check('new-password', $allowed->refresh()->password));
    }

    public function test_forgot_and_reset_routes_apply_named_rate_limits(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->forgot('forgot-rate-limit@example.com')->assertOk();
        }

        $this->forgot('forgot-rate-limit@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'PASSWORD_RESET_RATE_LIMIT_EXCEEDED');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->reset('reset-rate-limit@example.com')->assertStatus(422);
        }

        $this->reset('reset-rate-limit@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'PASSWORD_RESET_RATE_LIMIT_EXCEEDED');
    }

    public function test_reset_request_requires_configured_numeric_otp_and_rejects_token_field(): void
    {
        $user = User::factory()->create(['email' => 'reset-validation@example.com']);
        $this->forgot($user->email);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'obsolete-token',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->reset($user->email, str_repeat('1', (int) config('otp.length') - 1))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);
        $this->reset($user->email, str_repeat('a', (int) config('otp.length')))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['otp']);

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
        $this->assertDatabaseHas('password_reset_otps', ['user_id' => $user->id]);
    }

    public function test_password_reset_model_and_audits_hide_codes_passwords_and_tokens(): void
    {
        $user = User::factory()->create(['email' => 'safe-reset-audit@example.com']);
        $user->createToken('sensitive-token-name');
        $this->forgot($user->email);
        $otp = PasswordResetOtp::query()->firstOrFail();

        $this->assertArrayNotHasKey('code_hash', $otp->toArray());
        $this->reset($user->email)->assertOk();

        $audits = AuditLog::query()
            ->whereIn('action', ['password_reset_otp_issued', 'password_reset_completed'])
            ->get();
        $this->assertCount(2, $audits);

        foreach ($audits as $audit) {
            $this->assertSame(['driver' => 'static'], $audit->metadata);
            $this->assertPayloadDoesNotExposeSecrets($audit->toArray());
        }
    }

    private function forgot(string $email): TestResponse
    {
        return $this->postJson('/api/v1/auth/forgot-password', [
            'email' => $email,
        ]);
    }

    private function reset(
        string $email,
        ?string $otp = null,
        string $password = 'new-password',
    ): TestResponse {
        return $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email,
            'otp' => $otp ?? $this->staticOtp(),
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    private function verifyEmail(string $email): TestResponse
    {
        return $this->postJson('/api/v1/auth/email/verify-otp', [
            'email' => $email,
            'otp' => $this->staticOtp(),
        ]);
    }

    private function staticOtp(): string
    {
        return str_repeat('0', (int) config('otp.length'));
    }

    private function wrongOtp(): string
    {
        return str_repeat('1', (int) config('otp.length'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadDoesNotExposeSecrets(array $payload): void
    {
        array_walk_recursive($payload, function (mixed $value, mixed $key): void {
            $this->assertNotContains($key, [
                'code_hash',
                'password',
                'password_confirmation',
                'remember_token',
                'token',
            ]);
            $this->assertNotSame($this->staticOtp(), $value);
            $this->assertNotSame('new-password', $value);
        });
    }
}

<?php

namespace Tests\Feature\Api\V1;

use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\EmailVerificationOtp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class EmailVerificationOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_seeker_registration_creates_unverified_user_and_hashed_otp_without_exposing_secrets(): void
    {
        $response = $this->registerJobSeeker('candidate@example.com');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'candidate@example.com')
            ->assertJsonPath('data.user.email_verified_at', null)
            ->assertJsonPath('data.user.is_email_verified', false)
            ->assertJsonPath('data.email_verification.required', true)
            ->assertJsonPath('data.email_verification.delivery_channel', 'static')
            ->assertJsonPath('data.email_verification.sent', false)
            ->assertJsonPath('data.email_verification.expires_in_seconds', 300)
            ->assertJsonPath('data.email_verification.resend_after_seconds', 60);

        $user = User::query()->where('email', 'candidate@example.com')->firstOrFail();
        $otp = EmailVerificationOtp::query()->whereBelongsTo($user)->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertNotSame($this->staticOtp(), $otp->code_hash);
        $this->assertTrue(Hash::check($this->staticOtp(), $otp->code_hash));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertPayloadDoesNotExposeOtpSecrets($response->json());
    }

    public function test_employer_registration_creates_unverified_user_company_profile_and_otp(): void
    {
        $response = $this->registerEmployer('employer@example.com');

        $response->assertCreated()
            ->assertJsonPath('data.user.email_verified_at', null)
            ->assertJsonPath('data.user.is_email_verified', false)
            ->assertJsonPath('data.user.employer_profile.company.name', 'Example Hiring');

        $user = User::query()->where('email', 'employer@example.com')->firstOrFail();

        $this->assertDatabaseHas('email_verification_otps', ['user_id' => $user->id]);
        $this->assertDatabaseHas('employer_profiles', ['user_id' => $user->id]);
        $this->assertDatabaseHas('companies', ['name' => 'Example Hiring']);
    }

    public function test_correct_otp_verifies_consumes_record_and_returns_one_token(): void
    {
        $this->registerJobSeeker('verify@example.com');

        $response = $this->verify('verify@example.com');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Email verified successfully.')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.is_email_verified', true);

        $user = User::query()->where('email', 'verify@example.com')->firstOrFail();
        $token = $response->json('data.token');

        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseMissing('email_verification_otps', ['user_id' => $user->id]);
        $this->assertNotNull(PersonalAccessToken::findToken($token));
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->verify('verify@example.com')
            ->assertStatus(409)
            ->assertJsonPath('code', 'EMAIL_ALREADY_VERIFIED');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_incorrect_otp_increments_attempts_without_resetting_expiration(): void
    {
        $this->registerJobSeeker('incorrect@example.com');
        $before = EmailVerificationOtp::query()->firstOrFail()->expires_at->toISOString();

        $this->verify('incorrect@example.com', $this->wrongOtp())
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_OTP');

        $otp = EmailVerificationOtp::query()->firstOrFail();

        $this->assertSame(1, $otp->attempts);
        $this->assertSame($before, $otp->expires_at->toISOString());
    }

    public function test_maximum_attempts_blocks_later_verification(): void
    {
        config()->set('otp.max_attempts', 2);
        $this->registerJobSeeker('attempts@example.com');

        $this->verify('attempts@example.com', $this->wrongOtp())->assertStatus(422);
        $this->verify('attempts@example.com', $this->wrongOtp())->assertStatus(422);

        $this->verify('attempts@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_ATTEMPTS_EXCEEDED');

        $this->assertNull(User::query()->firstOrFail()->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_expired_otp_is_rejected(): void
    {
        $this->registerJobSeeker('expired@example.com');
        EmailVerificationOtp::query()->update(['expires_at' => now()->subSecond()]);

        $this->verify('expired@example.com')
            ->assertStatus(422)
            ->assertJsonPath('code', 'OTP_EXPIRED');
    }

    public function test_missing_verification_record_is_rejected(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'missing-otp@example.com']);

        $this->verify($user->email)
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_VERIFICATION_NOT_FOUND');
    }

    public function test_reissue_resets_attempts_and_refreshes_expiry_after_cooldown(): void
    {
        Carbon::setTestNow('2026-07-30 10:00:00');
        $this->registerJobSeeker('reissue@example.com');
        $otp = EmailVerificationOtp::query()->firstOrFail();
        $originalExpiry = $otp->expires_at->copy();
        $otp->forceFill(['attempts' => 3])->save();

        Carbon::setTestNow(now()->addSeconds(61));

        $this->resend('reissue@example.com')->assertOk();

        $otp->refresh();
        $this->assertSame(0, $otp->attempts);
        $this->assertTrue($otp->expires_at->greaterThan($originalExpiry));
        $this->assertSame(now()->addMinutes(5)->timestamp, $otp->expires_at->timestamp);

        Carbon::setTestNow();
    }

    public function test_reissue_enforces_database_cooldown_and_returns_retry_after(): void
    {
        $this->registerJobSeeker('cooldown@example.com');

        $this->resend('cooldown@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_RESEND_COOLDOWN')
            ->assertJsonStructure(['retry_after_seconds']);
    }

    public function test_unknown_and_verified_accounts_receive_same_generic_reissue_response(): void
    {
        User::factory()->create(['email' => 'verified@example.com']);

        $unknown = $this->resend('unknown@example.com')->assertOk();
        $verified = $this->resend('verified@example.com')->assertOk();

        $expected = 'If the account exists and requires verification, a new verification code is available.';
        $unknown->assertJsonPath('message', $expected);
        $verified->assertJsonPath('message', $expected);
        $this->assertSame($unknown->json('data'), $verified->json('data'));
    }

    public function test_login_requires_verification_then_succeeds_after_otp(): void
    {
        $this->registerJobSeeker('login-after@example.com');

        $this->postJson('/api/v1/auth/login', [
            'email' => ' LOGIN-AFTER@EXAMPLE.COM ',
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->verify(' LOGIN-AFTER@EXAMPLE.COM ')->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'login-after@example.com',
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 2);
    }

    public function test_suspended_status_check_still_precedes_verification_check(): void
    {
        User::factory()->unverified()->create([
            'email' => 'suspended-unverified@example.com',
            'status' => UserStatus::SUSPENDED,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended-unverified@example.com',
            'password' => 'password',
        ])->assertStatus(403)
            ->assertJsonPath('code', 'USER_SUSPENDED');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_duplicate_registration_behavior_is_preserved(): void
    {
        User::factory()->create(['email' => 'duplicate-otp@example.com']);

        $this->registerJobSeeker(' DUPLICATE-OTP@EXAMPLE.COM ')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_verify_route_rate_limit_uses_email_and_ip(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->verify('limited@example.com')->assertStatus(422);
        }

        $this->verify('limited@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_RATE_LIMIT_EXCEEDED');
    }

    public function test_reissue_route_rate_limit_uses_email_and_ip(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->resend('limited-resend@example.com')->assertOk();
        }

        $this->resend('limited-resend@example.com')
            ->assertStatus(429)
            ->assertJsonPath('code', 'OTP_RATE_LIMIT_EXCEEDED');
    }

    public function test_static_driver_is_unavailable_in_production_without_explicit_override(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('otp.allow_static_in_production', false);

        $this->registerJobSeeker('production-blocked@example.com')
            ->assertStatus(503)
            ->assertJsonPath('code', 'OTP_DRIVER_NOT_AVAILABLE');

        $this->assertDatabaseMissing('users', ['email' => 'production-blocked@example.com']);
        $this->assertDatabaseCount('email_verification_otps', 0);
    }

    public function test_static_driver_works_in_production_only_with_explicit_override(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('otp.allow_static_in_production', true);

        $this->registerJobSeeker('production-demo@example.com')->assertCreated();
        $this->verify('production-demo@example.com')->assertOk();
    }

    public function test_audit_records_and_model_serialization_do_not_expose_otp_secrets(): void
    {
        $this->registerJobSeeker('audit-otp@example.com');
        $otp = EmailVerificationOtp::query()->firstOrFail();

        $this->assertArrayNotHasKey('code_hash', $otp->toArray());

        $this->verify('audit-otp@example.com')->assertOk();

        $actions = AuditLog::query()
            ->whereIn('action', ['email_verification_otp_issued', 'email_verified'])
            ->get();

        $this->assertCount(2, $actions);
        foreach ($actions as $audit) {
            $this->assertSame(['driver' => 'static'], $audit->metadata);
            $this->assertPayloadDoesNotExposeOtpSecrets($audit->toArray());
        }
    }

    private function registerJobSeeker(string $email)
    {
        return $this->postJson('/api/v1/auth/register/job-seeker', [
            'name' => 'OTP Candidate',
            'email' => $email,
            'phone' => '+963 900 000 000',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ]);
    }

    private function registerEmployer(string $email)
    {
        return $this->postJson('/api/v1/auth/register/employer', [
            'name' => 'OTP Employer',
            'email' => $email,
            'company_name' => 'Example Hiring',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms_accepted' => true,
        ]);
    }

    private function verify(string $email, ?string $otp = null)
    {
        return $this->postJson('/api/v1/auth/email/verify-otp', [
            'email' => $email,
            'otp' => $otp ?? $this->staticOtp(),
        ]);
    }

    private function resend(string $email)
    {
        return $this->postJson('/api/v1/auth/email/resend-otp', [
            'email' => $email,
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
    private function assertPayloadDoesNotExposeOtpSecrets(array $payload): void
    {
        array_walk_recursive($payload, function (mixed $value, mixed $key): void {
            $this->assertNotSame('code_hash', $key);
            $this->assertNotSame($this->staticOtp(), $value);
        });
    }
}

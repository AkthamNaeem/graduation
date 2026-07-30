<?php

namespace Tests\Unit;

use App\Models\EmailVerificationOtp;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmailVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_generator_and_metadata_follow_configuration(): void
    {
        config()->set([
            'otp.driver' => 'static',
            'otp.length' => 4,
            'otp.ttl_minutes' => 7,
            'otp.resend_cooldown_seconds' => 45,
        ]);

        $user = User::factory()->unverified()->create();
        $service = app(EmailVerificationService::class);
        $otp = $service->issueOtp($user);

        $configuredCode = str_repeat('0', (int) config('otp.length'));

        $this->assertTrue(Hash::check($configuredCode, $otp->code_hash));
        $this->assertNotSame($configuredCode, $otp->code_hash);
        $this->assertSame(0, $otp->attempts);
        $this->assertSame(now()->addMinutes(7)->timestamp, $otp->expires_at->timestamp);
        $this->assertSame([
            'required' => true,
            'delivery_channel' => 'static',
            'sent' => false,
            'expires_in_seconds' => 420,
            'resend_after_seconds' => 45,
        ], $service->getVerificationMetadata());
        $this->assertDatabaseCount('email_verification_otps', 1);
        $this->assertArrayNotHasKey(
            'code_hash',
            EmailVerificationOtp::query()->firstOrFail()->toArray(),
        );
    }
}

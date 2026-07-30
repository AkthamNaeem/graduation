<?php

namespace Tests\Unit;

use App\Exceptions\EmailVerificationException;
use App\Services\Auth\OtpCodeService;
use Tests\TestCase;

class OtpCodeServiceTest extends TestCase
{
    public function test_static_code_uses_configured_length(): void
    {
        config()->set('otp.length', 4);

        $service = app(OtpCodeService::class);

        $this->assertSame('static', $service->driver());
        $this->assertSame(str_repeat('0', 4), $service->generate());
    }

    public function test_production_guard_requires_explicit_static_override(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('otp.allow_static_in_production', false);

        try {
            app(OtpCodeService::class)->ensureAvailable();
            $this->fail('The static OTP driver should be unavailable.');
        } catch (EmailVerificationException $exception) {
            $this->assertSame('OTP_DRIVER_NOT_AVAILABLE', $exception->errorCode);
            $this->assertSame(503, $exception->status);
        }

        config()->set('otp.allow_static_in_production', true);

        app(OtpCodeService::class)->ensureAvailable();
        $this->addToAssertionCount(1);
    }
}

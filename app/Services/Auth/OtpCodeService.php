<?php

namespace App\Services\Auth;

use App\Exceptions\EmailVerificationException;

class OtpCodeService
{
    public function ensureAvailable(): void
    {
        $staticAllowed = $this->driver() === 'static'
            && (! app()->environment('production')
                || (bool) config('otp.allow_static_in_production', false));

        if (! $staticAllowed) {
            throw new EmailVerificationException(__('domain_errors.OTP_DRIVER_NOT_AVAILABLE'), 'OTP_DRIVER_NOT_AVAILABLE',
                503,
            );
        }
    }

    public function generate(): string
    {
        return str_repeat('0', max(1, (int) config('otp.length')));
    }

    public function driver(): string
    {
        return (string) config('otp.driver', 'static');
    }
}

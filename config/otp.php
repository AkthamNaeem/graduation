<?php

return [
    'driver' => env('OTP_DRIVER', 'static'),
    'length' => (int) env('OTP_LENGTH', 6),
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 5),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    'allow_static_in_production' => (bool) env('OTP_ALLOW_STATIC_IN_PRODUCTION', false),
];

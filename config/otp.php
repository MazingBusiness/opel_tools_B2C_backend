<?php

return [
    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 300),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'length' => 6,

    // Send limits (POST /auth/otp/request only; verify has its own limiter).
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 45),
    'per_identifier_per_hour' => (int) env('OTP_PER_IDENTIFIER_PER_HOUR', 10),
    'per_ip_per_hour' => (int) env('OTP_PER_IP_PER_HOUR', 20),
];

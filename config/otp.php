<?php

return [
    'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 120),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'length' => 6,
];

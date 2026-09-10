<?php

namespace App\Modules\Auth\Services;

class OtpCodeGenerator
{
    public function generate(): string
    {
        $max = (10 ** config('otp.length')) - 1;
        $min = 10 ** (config('otp.length') - 1);

        return (string) random_int($min, $max);
    }
}

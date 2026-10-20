<?php

namespace App\Modules\Auth\Providers;

use App\Modules\Auth\Contracts\FirebaseIdTokenVerifier;
use App\Modules\Auth\Services\KreaitFirebaseIdTokenVerifier;
use App\Modules\Auth\Support\LoginIdentifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FirebaseIdTokenVerifier::class, KreaitFirebaseIdTokenVerifier::class);
    }

    public function boot(): void
    {
        RateLimiter::for('otp', function (Request $request) {
            $identifier = strtolower((string) $request->input('identifier'));

            return Limit::perMinute(5)->by($request->ip().'|'.$identifier);
        });

        // Sending codes costs money (WhatsApp/SMS): burst + per-identifier/hour + per-IP/hour.
        // Identifier is normalised so "98765 43210" and "+91 9876543210" share a bucket.
        RateLimiter::for('otp-send', function (Request $request) {
            $raw = (string) $request->input('identifier');
            try {
                $id = LoginIdentifier::parse($raw);
                $key = $id->channel.':'.$id->value;
            } catch (\InvalidArgumentException) {
                $key = 'raw:'.strtolower(trim($raw));
            }

            return [
                Limit::perMinute(5)->by('otp-send:burst:'.$request->ip().'|'.$key),
                Limit::perHour(max(1, (int) config('otp.per_identifier_per_hour', 10)))->by('otp-send:id:'.$key),
                Limit::perHour(max(1, (int) config('otp.per_ip_per_hour', 20)))->by('otp-send:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('google', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}

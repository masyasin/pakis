<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Http\ClientNetwork;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Perangkat & API Pakis Pro (T-031). Provider terpisah agar modul ini tidak menyentuh registrasi
 * modul lain. Limiter `login` milik web (FortifyServiceProvider) dipakai ulang untuk login API.
 */
class DeviceServiceProvider extends ServiceProvider
{
    /** SEC-068: request API umum per token per menit. */
    private const API_PER_MINUTE = 120;

    /** Lupa/reset password per jaringan per menit (lapisan kasar; batas per identifier ada di action). */
    private const PASSWORD_PER_MINUTE = 10;

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = $request->user();
            $token = $user instanceof User ? $user->currentAccessToken() : null;
            $key = $token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'ip:'.ClientNetwork::key($request->ip());

            return Limit::perMinute(self::API_PER_MINUTE)->by($key);
        });

        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(self::PASSWORD_PER_MINUTE)->by(ClientNetwork::key($request->ip())));
    }
}

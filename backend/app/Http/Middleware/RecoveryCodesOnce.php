<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-015 / 07 §4: kode pemulihan MFA hanya TAMPIL SEKALI, tepat setelah dibuat (aktivasi atau
 * "buat ulang"). Endpoint Fortify `GET user/two-factor-recovery-codes` aslinya bisa dipanggil
 * kapan saja; di sini hanya diizinkan satu kali setelah flag dari listener RecoveryCodesGenerated.
 * Sesi yang dibajak tidak bisa membaca kode pemulihan lama.
 */
class RecoveryCodesOnce
{
    public const SESSION_FLAG = 'mfa.recovery_codes_viewable';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->routeIs('two-factor.recovery-codes')
            && ! $request->session()->pull(self::SESSION_FLAG, false)) {
            abort(404);
        }

        return $next($request);
    }
}

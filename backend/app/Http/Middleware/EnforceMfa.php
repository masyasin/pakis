<?php

namespace App\Http\Middleware;

use App\Domain\Access\Support\MfaRequirement;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-015, SEC-007 (`mfa.enforced`, 05 §1):
 * - role wajib MFA + MFA belum aktif → semua halaman dialihkan ke `/aktivasi-mfa`, kecuali yang
 *   dibutuhkan untuk mengaktifkannya (endpoint Fortify `user/two-factor-*`, konfirmasi password);
 * - role wajib MFA → MFA tidak boleh dinonaktifkan pemiliknya (hanya admin lewat ResetMfa).
 * Dipasang SETELAH RequirePasswordChange: wajib ganti password diselesaikan lebih dulu.
 */
class EnforceMfa
{
    /** Route yang tetap terbuka selama aktivasi belum selesai. */
    private const ALLOWED = [
        'admin.mfa.setup',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
        'admin.password.force',
        'admin.password.force.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.disable') && MfaRequirement::requires($user)) {
            abort(403, 'Verifikasi dua langkah wajib untuk role Anda dan tidak bisa dinonaktifkan.');
        }

        if (! MfaRequirement::pending($user) || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        return $request->header('X-Inertia')
            ? Inertia::location(route('admin.mfa.setup'))
            : redirect()->route('admin.mfa.setup');
    }
}

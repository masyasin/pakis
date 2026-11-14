<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Batas umur sesi web (BR-018, SEC-005): idle `security.session_idle_minutes` dan absolut
 * `security.session_absolute_hours`. Penanda disimpan di sesi saat login; lewat batas → keluar.
 */
class EnforceSessionLifetime
{
    private const STARTED = 'pakis.session_started_at';

    private const ACTIVITY = 'pakis.session_last_activity';

    public static function start(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put([self::STARTED => now()->getTimestamp(), self::ACTIVITY => now()->getTimestamp()]);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('web')->check() || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();

        // Sesi tanpa penanda (mis. dibuat sebelum fitur ini) dimulai dari sekarang.
        if (! $session->has(self::STARTED)) {
            self::start($request);
        }

        $idleLimit = (int) setting('security.session_idle_minutes') * 60;
        $absoluteLimit = (int) setting('security.session_absolute_hours') * 3600;

        $message = match (true) {
            $now - (int) $session->get(self::STARTED) > $absoluteLimit => 'Sesi Anda sudah terlalu lama. Silakan masuk lagi.',
            $now - (int) $session->get(self::ACTIVITY) > $idleLimit => 'Sesi Anda berakhir karena tidak aktif. Silakan masuk lagi.',
            default => null,
        };

        if ($message !== null) {
            return LogsOut::to($request, $message);
        }

        $session->put(self::ACTIVITY, $now);

        return $next($request);
    }
}

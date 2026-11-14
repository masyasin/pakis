<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers SEC-063 untuk semua respons (web & API).
 *
 * CSP memakai nonce per request. Vite::useCspNonce() membuat nonce yang sama dipasang di tag
 * script/style hasil @vite dan @fonts, serta di tag inline app.blade.php lewat Vite::cspNonce().
 * Mode CSP dari config('pakis.csp.mode'): enforce | report-only | off.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('pakis.csp.mode', 'report-only');
        $nonce = $mode === 'off' ? null : Vite::useCspNonce();

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // Web admin tidak butuh kamera/GPS/mikrofon; Pakis Pro (Flutter) tidak terpengaruh header ini.
        $headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');

        // PHP menambah X-Powered-By di luar objek Response; hapus di keduanya.
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($nonce !== null) {
            $name = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $headers->set($name, $this->policy($nonce));
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $self = "'self'";
        $script = [$self, "'nonce-{$nonce}'"];
        // Style TANPA nonce, dengan 'unsafe-inline': library (progress bar Inertia, Sonner, React)
        // menyisipkan <style> lewat JS saat runtime tanpa nonce, dan browser mengabaikan
        // 'unsafe-inline' bila ada nonce di direktif yang sama. Terbukti diblokir di Chromium (T-003).
        // Risiko injeksi CSS jauh lebih kecil daripada script; script tetap wajib nonce.
        $style = [$self, "'unsafe-inline'"];
        $connect = [$self];

        // Vite dev server (composer run dev) berjalan di origin lain dan memakai websocket HMR.
        if (Vite::isRunningHot()) {
            $hot = rtrim((string) file_get_contents(Vite::hotFile()));
            $script[] = $hot;
            $style[] = $hot;
            $connect[] = $hot;
            $connect[] = preg_replace('#^http#', 'ws', $hot);
        }

        $directives = [
            'default-src' => [$self],
            'script-src' => $script,
            'style-src' => $style,
            // Font di-self-host ke build/assets oleh plugin bunny (vite.config.ts).
            'font-src' => [$self],
            'img-src' => [$self, 'data:', 'blob:'],
            'connect-src' => $connect,
            'frame-ancestors' => ["'none'"],
            'base-uri' => [$self],
            'form-action' => [$self],
            'object-src' => ["'none'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }
}

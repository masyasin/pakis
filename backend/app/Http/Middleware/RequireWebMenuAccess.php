<?php

namespace App\Http\Middleware;

use App\Domain\Access\Support\WebAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-017 untuk sesi yang sedang berjalan: bila semua permission menu web dicabut (mis. role diganti
 * menjadi `pegawai` saja), sesi web diakhiri dengan pesan Pakis Pro. Dipasang di route aplikasi
 * (alias `web.access`), tidak di route login/logout.
 */
class RequireWebMenuAccess
{
    public function __construct(private readonly WebAccess $webAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $this->webAccess->allows($user)) {
            return LogsOut::to($request, WebAccess::MESSAGE);
        }

        return $next($request);
    }
}

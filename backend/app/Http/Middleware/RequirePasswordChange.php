<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-006 / FR-007: akun `must_change_password` (reset admin, akun awal) hanya boleh membuka
 * halaman ganti password dan keluar. Route lain dialihkan ke `/ganti-password`.
 */
class RequirePasswordChange
{
    /** Route yang tetap boleh selama kewajiban belum dipenuhi. */
    private const ALLOWED = ['admin.password.force', 'admin.password.force.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        return $request->header('X-Inertia')
            ? Inertia::location(route('admin.password.force'))
            : redirect()->route('admin.password.force');
    }
}

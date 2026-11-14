<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keluarkan sesi web secara paksa (timeout, akun dinonaktifkan, kehilangan akses) lalu arahkan ke
 * login dengan pesan. Request Inertia mendapat 409 + lokasi agar halaman dimuat ulang penuh.
 */
final class LogsOut
{
    public static function to(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', $message);

        return $request->header('X-Inertia')
            ? Inertia::location(route('login'))
            : redirect()->route('login');
    }
}

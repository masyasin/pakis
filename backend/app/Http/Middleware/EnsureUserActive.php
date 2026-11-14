<?php

namespace App\Http\Middleware;

use App\Domain\Access\Support\EmployeeStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi berjalan milik akun yang sudah tidak boleh login (dinonaktifkan, dihapus, dikunci, pegawai
 * nonaktif) langsung keluar (BR-010, SEC-021). Pesan generik, sama dengan login gagal.
 */
class EnsureUserActive
{
    public function __construct(private readonly EmployeeStatus $employees) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        // Akun yang dihapus (soft delete) tidak lagi ditemukan guard → user() null, sesi tetap ada.
        if ($user === null && Auth::guard('web')->id() !== null) {
            return LogsOut::to($request, 'Sesi Anda berakhir. Silakan masuk lagi.');
        }

        if ($user instanceof User && (! $user->is_active || $user->trashed() || $user->isLocked() || ! $this->employees->isActive($user))) {
            return LogsOut::to($request, 'Sesi Anda berakhir. Silakan masuk lagi.');
        }

        return $next($request);
    }
}

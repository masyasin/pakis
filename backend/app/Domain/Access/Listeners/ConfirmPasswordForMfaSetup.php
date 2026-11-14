<?php

namespace App\Domain\Access\Listeners;

use App\Domain\Access\Support\MfaRequirement;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Pengguna yang WAJIB mengaktifkan MFA (BR-015) langsung dibawa ke /aktivasi-mfa setelah login,
 * dan halaman itu (serta endpoint aktivasi Fortify) butuh konfirmasi password. Password baru saja
 * diketik di form login, jadi login itu dicatat sebagai konfirmasi — tanpa ini pengguna diminta
 * mengetik password dua kali berturut-turut. Hanya untuk kasus wajib-aktivasi; pengguna lain
 * tetap mengonfirmasi ulang sebelum aksi sensitif (06 §1.6).
 */
final class ConfirmPasswordForMfaSetup
{
    public function __construct(private readonly Request $request) {}

    public function handle(Login $event): void
    {
        if ($event->user instanceof User && $this->request->hasSession() && MfaRequirement::pending($event->user)) {
            $this->request->session()->passwordConfirmed();
        }
    }
}

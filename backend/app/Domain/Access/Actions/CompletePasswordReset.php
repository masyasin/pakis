<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Notifications\PasswordChanged;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Selesaikan reset password (BR-012/BR-013), dipakai alur email & OTP. Password SUDAH divalidasi
 * PasswordPolicy dan token/OTP SUDAH diperiksa oleh pemanggil.
 *
 * Semua sesi web lama keluar otomatis (AuthenticateSession: hash password berubah).
 * TODO(T-031): cabut semua token API milik pengguna.
 */
final class CompletePasswordReset
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function handle(User $user, string $password, string $via): void
    {
        DB::transaction(function () use ($user, $password, $via) {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'password_changed_at' => now(),
                'locked_until' => null,
            ]);

            // Tautan email sampai & dipakai = bukti kepemilikan alamat. Akun yang dibuat admin
            // dengan "kirim tautan atur password" menjadi terverifikasi di sini (T-020).
            if ($via === 'email' && $user->email !== null && $user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()]);
            }

            $user->save();

            $this->security->record(SecurityEventType::PasswordResetCompleted, SecurityChannel::Web, $user, $user->username, ['via' => $via]);
        });

        if ($user->email) {
            $user->notify(new PasswordChanged(now()));
        }
    }
}

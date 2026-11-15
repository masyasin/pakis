<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cabut semua sesi web akun lain oleh admin (05 §4.4 `admin.users.revoke-sessions`), juga dipakai
 * saat menonaktifkan akun dan reset password admin (BR-014). Baris sesi dihapus langsung: sesi
 * yang terhapus tidak bisa dipakai lagi pada request berikutnya.
 * TODO(T-031): cabut juga token API (Sanctum) milik pengguna — tabelnya dibuat di T-031.
 */
final class RevokeUserSessions
{
    public function __construct(private readonly SecurityLogger $security) {}

    /** @return int jumlah sesi yang dicabut */
    public function handle(User $target): int
    {
        return DB::transaction(function () use ($target) {
            $count = ListSessions::query($target)->delete();

            if ($count > 0) {
                $this->security->record(SecurityEventType::SessionRevoked, SecurityChannel::Web, $target, $target->username, [
                    'count' => $count,
                    'by_admin' => true,
                ]);
            }

            return $count;
        });
    }
}

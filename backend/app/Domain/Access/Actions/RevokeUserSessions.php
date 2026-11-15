<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Device\Support\DeviceTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cabut semua sesi web akun lain oleh admin (05 §4.4 `admin.users.revoke-sessions`), juga dipakai
 * saat menonaktifkan akun dan reset password admin (BR-014). Baris sesi dihapus langsung: sesi
 * yang terhapus tidak bisa dipakai lagi pada request berikutnya. Token API (Pakis Pro) ikut dicabut
 * (T-031); perangkatnya tetap `active`, pemilik cukup login ulang.
 */
final class RevokeUserSessions
{
    public function __construct(
        private readonly SecurityLogger $security,
        private readonly DeviceTokens $tokens,
    ) {}

    /** @return int jumlah sesi yang dicabut */
    public function handle(User $target): int
    {
        return DB::transaction(function () use ($target) {
            $count = ListSessions::query($target)->delete();
            $this->tokens->revokeForUser($target, 'sessions_revoked_by_admin');

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

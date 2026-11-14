<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Reset MFA pengguna oleh admin (BR-015), mis. HP autentikator hilang. Dipanggil route
 * `admin.users.reset-mfa` (T-020). Aturan wewenang mengikuti pola reset password BR-014:
 * tidak untuk diri sendiri, Super Admin tidak bisa mereset Owner, hanya Owner yang bisa mereset
 * Owner lain (SEC-009). Semua sesi web target dicabut agar perangkat lama ikut keluar; saat login
 * berikutnya, role wajib MFA memaksa aktivasi ulang.
 * TODO(T-031): cabut juga token API milik target.
 */
final class ResetMfa
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
    ) {}

    public function handle(User $actor, User $target): void
    {
        self::assertAllowed($actor, $target);

        DB::transaction(function () use ($target) {
            $hadMfa = $target->two_factor_confirmed_at !== null;

            $target->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            ListSessions::query($target)->delete();

            $this->audit->record('user.mfa_reset', $target, ['mfa' => $hadMfa ? 'aktif' : 'tidak aktif'], ['mfa' => 'direset']);
            $this->security->record(SecurityEventType::MfaResetByAdmin, SecurityChannel::Web, $target, $target->username);
        });
    }

    /** Aturan wewenang satu sumber: UserPolicy::resetMfaOf (ability `users.reset-mfa-of`, T-019). */
    public static function assertAllowed(User $actor, User $target): void
    {
        if (Gate::forUser($actor)->denies('users.reset-mfa-of', $target)) {
            throw new AuthorizationException($actor->is($target)
                ? 'Anda tidak bisa mereset MFA akun sendiri.'
                : 'Anda tidak berwenang mereset MFA akun ini.');
        }
    }
}

<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Support\TemporaryPassword;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reset password oleh admin (FR-007, BR-014, SEC-006). Password sementara dikembalikan SEKALI ke
 * pemanggil; ia tidak dikirim lewat email/WA dan tidak masuk audit, log, sesi, atau flash.
 * `must_change_password` memaksa ganti password di web & API (RequirePasswordChange), dan semua sesi
 * pengguna dicabut. Wewenang (bukan diri sendiri, Owner hanya oleh Owner, hak target ⊆ pelaku)
 * dicek controller lewat ability `users.reset-password-of`.
 */
final class AdminResetPassword
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
        private readonly RevokeUserSessions $sessions,
    ) {}

    public function handle(User $actor, User $target): string
    {
        $temporary = TemporaryPassword::make();

        DB::transaction(function () use ($actor, $target, $temporary) {
            $target->forceFill([
                'password' => $temporary,
                'must_change_password' => true,
                // Akun yang terkunci karena salah password berulang bisa langsung dipakai lagi.
                'locked_until' => null,
                'updated_by' => $actor->id,
            ])->save();

            $this->sessions->handle($target);

            $this->audit->record('user.password_reset', $target, null, ['must_change_password' => true]);
            $this->security->record(SecurityEventType::PasswordResetByAdmin, SecurityChannel::Web, $target, $target->username);
        });

        return $temporary;
    }
}

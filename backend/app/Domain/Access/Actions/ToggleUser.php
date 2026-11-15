<?php

namespace App\Domain\Access\Actions;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aktif/nonaktifkan akun (FR-016, 05 §4.4). Nonaktif = semua sesi dicabut saat itu juga; middleware
 * `active` (EnsureUserActive) menolak sisa request yang masih membawa sesi lama. Owner bisa
 * menonaktifkan akun Super Admin vendor (SEC-009); wewenang dicek controller lewat UserPolicy.
 */
final class ToggleUser
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RevokeUserSessions $sessions,
    ) {}

    public function handle(User $actor, User $target, bool $active): void
    {
        if ($target->is_active === $active) {
            return;
        }

        DB::transaction(function () use ($actor, $target, $active) {
            $target->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();

            if (! $active) {
                $this->sessions->handle($target);
            }

            $this->audit->record($active ? 'user.activated' : 'user.deactivated', $target, ['is_active' => ! $active], ['is_active' => $active]);
        });
    }
}

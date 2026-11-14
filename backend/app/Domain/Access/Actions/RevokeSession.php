<?php

namespace App\Domain\Access\Actions;

use App\Domain\Access\Support\SessionKey;
use App\Domain\Audit\Enums\SecurityChannel;
use App\Domain\Audit\Enums\SecurityEventType;
use App\Domain\Audit\SecurityLogger;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Cabut satu sesi web milik sendiri (FR-003). Kunci dicocokkan HANYA di antara sesi milik
 * pengguna ini, jadi sesi orang lain tidak pernah bisa ditemukan → 404 (bukan 403, agar
 * keberadaannya tidak bocor). Sesi yang sedang dipakai juga 404: untuk itu ada tombol Keluar.
 */
final class RevokeSession
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function handle(User $user, string $key, string $currentSessionId): void
    {
        if (! SessionKey::isWellFormed($key)) {
            throw new ModelNotFoundException;
        }

        $sessionId = ListSessions::query($user)->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->first(fn (string $id) => hash_equals(SessionKey::of($id), $key));

        if ($sessionId === null || hash_equals($sessionId, $currentSessionId)) {
            throw new ModelNotFoundException;
        }

        DB::transaction(function () use ($user, $sessionId) {
            ListSessions::query($user)->where('id', $sessionId)->delete();

            // ID sesi tidak dicatat (kredensial); cukup jumlahnya.
            $this->security->record(SecurityEventType::SessionRevoked, SecurityChannel::Web, $user, $user->username, ['count' => 1]);
        });
    }
}
